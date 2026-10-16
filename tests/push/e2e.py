import sys,json,re,uuid,time,subprocess,hashlib,base64,os
sys.path.insert(0,'/tmp/dev')
from drive import *
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives import serialization
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
def php(code): return subprocess.run(['php','-r',"require '/home/claude/messenger/app/bootstrap.php'; "+code],capture_output=True,text=True).stdout.strip()
b64=lambda b: base64.urlsafe_b64encode(b).rstrip(b'=').decode()
def login(email,pw):
    op,cj=make(); s,h,_=req(op,'GET','/login'); t=csrf(h)
    s,h,hd=req(op,'POST','/login',{'email':email,'password':pw,'_csrf':t}); assert s==302
    s,h,_=req(op,'GET','/api/bootstrap',headers={'Accept':'application/json'}); return op,json.loads(h)
def api(op,b,method,path,data=None):
    hd={'X-CSRF-Token':b['csrf'],'Accept':'application/json','Content-Type':'application/json'}
    s,h,_=req(op,method,path,raw=json.dumps(data).encode() if data is not None else None,headers=hd)
    try: j=json.loads(h)
    except Exception: j=h
    return s,j
ok=True
def chk(n,c,i=''):
    global ok; ok&=bool(c); print(('PASS ' if c else 'FAIL ')+n,i)
def received(): 
    return [json.loads(l) for l in open('/tmp/pushmock/received.jsonl')] if os.path.exists('/tmp/pushmock/received.jsonl') else []
def reset(): open('/tmp/pushmock/received.jsonl','w').close()
def wait(n,t=6):
    e=time.time()+t
    while time.time()<e:
        if len(received())>=n: break
        time.sleep(0.2)
    return received()
def setmode(m):
    st=json.load(open('/tmp/pushmock/state.json')); st['mode']=m; json.dump(st,open('/tmp/pushmock/state.json','w'))

# --- Einrichtung
print(php("use App\\Services\\{Push,Settings}; Push::ensureKeys(); Settings::set('push_enabled','1'); echo Settings::get('vapid_public');")[:12])
vap=php("echo App\\Services\\Settings::get('vapid_public');")
anna=int(sql("select id from users where email='anna@muster.ch'")); bob=int(sql("select id from users where email='bob@muster.ch'"))
sql("delete from push_subscriptions")
devs={}
for did,keys in (('encA',True),('legacyB',False)):
    pk=ec.generate_private_key(ec.SECP256R1()); pem=pk.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()).decode()
    pub=pk.public_key().public_bytes(serialization.Encoding.X962,serialization.PublicFormat.UncompressedPoint); auth=os.urandom(16)
    devs[did]={'pem':pem,'auth':b64(auth),'p256dh':b64(pub)}
json.dump({'vapid_public':vap,'devices':devs,'mode':'ok'},open('/tmp/pushmock/state.json','w'))
A,ba=login('anna@muster.ch','Sehr-sicher-2026!'); B,bb=login('bob@muster.ch','Bob-Passwort-2026!')
def subscribe(did,keys=True):
    ep=f'http://127.0.0.1:8099/push/{did}'
    d={'endpoint':ep}
    if keys: d.update(p256dh=devs[did]['p256dh'],auth=devs[did]['auth'])
    return api(A,ba,'POST','/api/push/subscribe',d)
s,j=subscribe('encA'); chk('subscribe with keys (test host allowed)',s==201,(s,j))
s,j=subscribe('legacyB',False); chk('subscribe legacy (no keys)',s==201,(s,j))
row=sql("select (p256dh is not null), (auth_secret is not null) from push_subscriptions where endpoint like '%encA'"); chk('keys stored',row=='1\t1',row)
s,j=api(A,ba,'POST','/api/push/subscribe',{'endpoint':'http://127.0.0.1:8099/push/x','p256dh':'AAAA','auth':'BBBB'}); chk('bad keys: stored without keys',s==201 and sql("select p256dh is null from push_subscriptions where endpoint like '%/x'")=='1',(s,))
sql("delete from push_subscriptions where endpoint like '%/x'")
s,j=api(A,ba,'POST','/api/push/subscribe',{'endpoint':'https://evil.example/push'}); chk('foreign host refused',s==422,s)
s,j=api(A,ba,'POST','/api/conversations/direct',{'user_id':bob}); cid=j['conversation']['id']
sql("update users set last_visible_at=NULL, last_seen_at=UTC_TIMESTAMP(), last_active_at=UTC_TIMESTAMP() where id=%d"%anna)  # «online», aber nicht sichtbar (Tab im Hintergrund)
def send(text,conv=None,frm=(B,bb)):
    s,j=api(frm[0],frm[1],'POST',f'/api/conversations/{conv or cid}/messages',{'body':text,'client_uid':str(uuid.uuid4())}); assert s==201,(s,j); return j['message']['id']

# --- 1) Hintergrund: Anna zuletzt vor Sekunden «online», aber App nicht sichtbar -> Push muss kommen
reset(); mid=send('Geheimer Inhalt 123')
r=wait(2); chk('push to both devices while app hidden/online-recent',len(r)==2,[x['device'] for x in r])
byd={x['device']:x for x in r}
e=byd.get('encA',{}); chk('encrypted payload decrypts, correct text',e.get('payload',{}).get('body')=='Neue Nachricht von Bob Beispiel',e.get('payload'))
chk('payload url/tag/badge',e['payload']['url']==f'?c={cid}&m={mid}' and e['payload']['tag']==f'conv-{cid}' and e['payload']['badge']>=1,e['payload'])
chk('payload has NO message text','Geheimer' not in json.dumps(e['payload']))
chk('VAPID JWT valid per independent verifier',e.get('jwt')=='ok' and not e['problems'],e['problems'])
chk('headers: TTL, Urgency high, Topic, aes128gcm',e['headers'].get('Urgency')=='high' and e['headers'].get('Content-Encoding')=='aes128gcm' and e['headers'].get('Topic')==f'conv-{cid}' and e['headers'].get('TTL')=='86400',e['headers'])
l=byd.get('legacyB',{}); chk('legacy device gets empty push',l.get('payload') is None and not l['problems'],l.get('problems'))
st=sql("select last_status, fail_count from push_subscriptions where endpoint like '%encA'"); chk('status recorded',st.startswith('201\t0'),st)

# --- 2) Sichtbar: kein Push
s,j=api(A,ba,'GET',f'/api/poll?cursor={ba["cursor"]}&view=0&visible=1'); chk('poll visible ok',s==200)
reset(); send('während sichtbar'); time.sleep(1.5); chk('no push while app visible',len(received())==0,received())
# --- 3) Beacon «hidden» -> wieder Push
s,j=api(A,ba,'POST','/api/presence/hidden',{}); chk('hidden beacon',s==200)
reset(); send('nach dem Schliessen'); r=wait(2); chk('push right after hidden beacon (no 90s wait)',len(r)==2)
# --- 4) Hintergrund-Poll (visible=0) hält nicht fälschlich «sichtbar»
api(A,ba,'GET',f'/api/poll?cursor={ba["cursor"]}&view=0&visible=1'); api(A,ba,'GET',f'/api/poll?cursor={ba["cursor"]}&view=0&visible=0')
sql("update users set last_visible_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 45 SECOND) where id=%d"%anna)
reset(); send('Tab im Hintergrund, Poll läuft'); r=wait(2); chk('push when last visible >30s ago even though polling',len(r)==2)
# --- 5) Gruppen-Erwähnung
s,j=api(B,bb,'POST','/api/conversations/group',{'name':'Team Push','member_ids':[anna]}); gid=j['conversation']['id']
reset(); s,j=api(B,bb,'POST',f'/api/conversations/{gid}/messages',{'body':'Hallo @anna bitte prüfen','client_uid':str(uuid.uuid4())}); r=wait(2)
chk('mention in group pushes',len(r)==2 and 'erwähnt' in (r[0]['payload'] or r[1]['payload'])['body'],[x['payload'] for x in r])
# --- 6) 410 löscht, 500 behält
setmode('410'); reset(); send('x410'); wait(2); time.sleep(.5)
chk('410 removes subscriptions',sql("select count(*) from push_subscriptions")=='0',sql("select count(*) from push_subscriptions"))
subscribe('encA'); subscribe('legacyB',False); setmode('500'); reset(); send('x500'); wait(2); time.sleep(.5)
row=sql("select count(*), max(fail_count), max(last_status), max(last_error) from push_subscriptions"); chk('500 keeps subscriptions, records error',row.startswith('2\t1\t500\tHTTP 500'),row)
setmode('403'); reset(); send('x403'); wait(2); time.sleep(.5)
chk('403 (config error) does NOT delete subscriptions',sql("select count(*) from push_subscriptions")=='2')
setmode('ok'); reset(); send('wieder ok'); r=wait(2); time.sleep(.5)
chk('recovers, error cleared',len(r)==2 and sql("select count(*) from push_subscriptions where last_error is null and fail_count=0")=='2')
# --- 7) resubscribe ohne Sitzung
anon,_=make()
def anon_post(path,data):
    s,h,_=req(anon,'POST',path,raw=json.dumps(data).encode(),headers={'Content-Type':'application/json','Accept':'application/json'}); return s,h
s,h=anon_post('/api/push/resubscribe',{'old_endpoint':'http://127.0.0.1:8099/push/unknown','endpoint':'http://127.0.0.1:8099/push/new'}); chk('resubscribe unknown old endpoint refused',s==422,s)
s,h=anon_post('/api/push/resubscribe',{'old_endpoint':'http://127.0.0.1:8099/push/encA','endpoint':'https://evil.example/x'}); chk('resubscribe to foreign host refused',s==422,s)
devs['encA2']=devs['encA']; st=json.load(open('/tmp/pushmock/state.json')); st['devices']=devs; json.dump(st,open('/tmp/pushmock/state.json','w'))
s,h=anon_post('/api/push/resubscribe',{'old_endpoint':'http://127.0.0.1:8099/push/encA','endpoint':'http://127.0.0.1:8099/push/encA2','p256dh':devs['encA']['p256dh'],'auth':devs['encA']['auth']}); chk('resubscribe with old endpoint works',s==200,(s,h))
chk('row moved to new endpoint',sql("select count(*) from push_subscriptions where endpoint like '%%encA2' and user_id=%d"%anna)=='1' and sql("select count(*) from push_subscriptions where endpoint like '%/encA'")=='0')
reset(); send('an neue Adresse'); r=wait(2); chk('push reaches renewed endpoint',any(x['device']=='encA2' and x['payload'] for x in r),[x['device'] for x in r])
s,h,_=req(anon,'GET','/api/push/key',headers={'Accept':'application/json'}); chk('public vapid key endpoint',s==200 and json.loads(h)['public_key']==vap,s)
# --- 8) Admin-Test
import urllib.parse
op,_=make()
s,h,_=req(op,'GET','/login'); t=csrf(h); req(op,'POST','/login',{'email':'anna@muster.ch','password':'Sehr-sicher-2026!','_csrf':t})
s,h,_=req(op,'GET','/admin/operations'); t=csrf(h); chk('admin page lists devices',s==200 and 'Meine Push-Geräte' in h)
reset(); s,h,_=req(op,'POST','/admin/operations/test-push',{'_csrf':t}); s,h,_=req(op,'GET','/admin/operations')
m=re.findall(r'class="alert[^"]*"[^>]*>.*?<span[^>]*>([^<]*)',h,re.S); chk('admin test push reports success',any('angenommen' in x for x in m),m[:2])
r=received(); chk('test payload delivered',any(x['payload'] and 'Test' in x['payload']['body'] for x in r))
setmode('403'); s,h,_=req(op,'GET','/admin/operations'); t=csrf(h); s,h,_=req(op,'POST','/admin/operations/test-push',{'_csrf':t}); s,h,_=req(op,'GET','/admin/operations')
m=re.findall(r'class="alert[^"]*"[^>]*>.*?<span[^>]*>([^<]*)',h,re.S); chk('admin test shows failure reason',any('HTTP 403' in x for x in m),m[:2])
setmode('ok')
print('ALL OK' if ok else 'SOME FAILED')
