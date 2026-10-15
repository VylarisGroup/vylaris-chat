import qrcode from '../vendor/qrcode/qrcode.js';

const canvas = document.getElementById('qr');
if (canvas) {
  const qr = qrcode(0, 'M');
  qr.addData(canvas.dataset.uri);
  qr.make();
  const n = qr.getModuleCount();
  const px = 6;
  const quiet = 2;
  const size = (n + quiet * 2) * px;
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, size, size);
  ctx.fillStyle = '#000';
  for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (qr.isDark(y, x)) ctx.fillRect((x + quiet) * px, (y + quiet) * px, px, px);
}
