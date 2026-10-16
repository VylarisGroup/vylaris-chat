# Mock-Push-Dienst: prüft wie FCM/Mozilla/Apple (VAPID-JWT, Header, Verschlüsselung) und entschlüsselt mit dem Geräteschlüssel.
import json,base64,struct,time,sys,os
from http.server import BaseHTTPRequestHandler, HTTPServer
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric.utils import encode_dss_signature
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
b64d=lambda s: base64.urlsafe_b64decode(s+'='*(-len(s)%4))
STATE='/tmp/pushmock/state.json'   # {"vapid_public":..., "devices":{id:{"pem":..,"auth":..}}, "mode":"ok"|"410"|"500"|"403"}
LOG='/tmp/pushmock/received.jsonl'
class H(BaseHTTPRequestHandler):
    def log_message(self,*a): pass
    def do_POST(self):
        st=json.load(open(STATE)); did=self.path.rsplit('/',1)[-1]
        n=int(self.headers.get('Content-Length') or 0); body=self.rfile.read(n)
        rec={'device':did,'problems':[],'headers':{k:v for k,v in self.headers.items() if k.lower() in ('ttl','urgency','topic','content-encoding','content-type')}}
        mode=st.get('mode','ok')
        auth=self.headers.get('Authorization','')
        try:
            assert auth.startswith('vapid '), 'Authorization-Schema'
            parts=dict(p.strip().split('=',1) for p in auth[6:].split(','))
            jwt,k=parts['t'],parts['k']
            assert k==st['vapid_public'],'k stimmt nicht mit dem VAPID-Schlüssel überein'
            h,c,s=jwt.split('.')
            hdr=json.loads(b64d(h)); cl=json.loads(b64d(c)); sig=b64d(s)
            assert hdr=={'typ':'JWT','alg':'ES256'}, hdr
            assert len(sig)==64,'Signaturlänge'
            pub=ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(),b64d(k))
            pub.verify(encode_dss_signature(int.from_bytes(sig[:32],'big'),int.from_bytes(sig[32:],'big')),f'{h}.{c}'.encode(),ec.ECDSA(hashes.SHA256()))
            assert cl['aud']=='http://127.0.0.1:%d'%self.server.server_port,'aud '+cl['aud']
            assert cl['exp']>time.time() and cl['exp']<=time.time()+86400,'exp'
            assert cl['sub'].startswith('mailto:') or cl['sub'].startswith('https://'),'sub '+cl['sub']
            rec['jwt']='ok'
        except Exception as e: rec['problems'].append('VAPID: %r'%e)
        for hname in ('TTL','Urgency'):
            if not self.headers.get(hname): rec['problems'].append('Header fehlt: '+hname)
        dev=st['devices'].get(did)
        if len(body)==0: rec['payload']=None
        else:
            try:
                assert self.headers.get('Content-Encoding')=='aes128gcm'
                priv=serialization.load_pem_private_key(dev['pem'].encode(),None)
                ua=priv.public_key().public_bytes(serialization.Encoding.X962,serialization.PublicFormat.UncompressedPoint)
                salt=body[:16]; rs=struct.unpack('>I',body[16:20])[0]; il=body[20]; asp=body[21:21+il]; ct=body[21+il:]
                assert rs>=4096 and len(body)<=4096+86
                shared=priv.exchange(ec.ECDH(),ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(),asp))
                ikm=HKDF(hashes.SHA256(),32,b64d(dev['auth']),b'WebPush: info\0'+ua+asp).derive(shared)
                cek=HKDF(hashes.SHA256(),16,salt,b'Content-Encoding: aes128gcm\0').derive(ikm)
                nonce=HKDF(hashes.SHA256(),12,salt,b'Content-Encoding: nonce\0').derive(ikm)
                pt=AESGCM(cek).decrypt(nonce,ct,None); assert pt[-1]==2
                rec['payload']=json.loads(pt[:-1].decode())
            except Exception as e: rec['problems'].append('Entschlüsselung: %r'%e)
        rec['at']=time.time(); rec['mode']=mode
        open(LOG,'a').write(json.dumps(rec,ensure_ascii=False)+'\n')
        code={'ok':201,'410':410,'404':404,'500':500,'403':403,'429':429}.get(mode,201)
        if rec['problems'] and mode=='ok': code=403
        self.send_response(code); self.send_header('Content-Length','0'); self.end_headers()
HTTPServer(('127.0.0.1',8099),H).serve_forever()
