/** Draws a shareable PNG summary of a workout on a canvas (nothing leaves the device). */
const W = 1080;
const H = 1350;

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

export async function buildShareCard(data) {
    try { await document.fonts.ready; await document.fonts.load('700 40px Orbitron'); } catch { /* fall back to system fonts */ }

    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');

    const bg = ctx.createLinearGradient(0, 0, 0, H);
    bg.addColorStop(0, '#0d1a12');
    bg.addColorStop(1, '#07090a');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, W, H);

    ctx.strokeStyle = 'rgba(57,255,20,0.07)';
    ctx.lineWidth = 1;
    for (let x = 0; x <= W; x += 54) { ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke(); }
    for (let y = 0; y <= H; y += 54) { ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke(); }

    const glow = ctx.createRadialGradient(W / 2, 360, 20, W / 2, 360, 520);
    glow.addColorStop(0, 'rgba(57,255,20,0.28)');
    glow.addColorStop(1, 'rgba(57,255,20,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, W, 760);

    const display = '"Orbitron", ui-sans-serif, system-ui, sans-serif';
    const body = '"Inter", ui-sans-serif, system-ui, sans-serif';
    ctx.textAlign = 'center';

    ctx.fillStyle = '#39ff14';
    ctx.font = `700 34px ${display}`;
    ctx.fillText('IRONPULSE', W / 2, 110);

    ctx.shadowColor = 'rgba(57,255,20,0.8)';
    ctx.shadowBlur = 36;
    ctx.fillStyle = '#e8f5e9';
    ctx.font = `900 84px ${display}`;
    ctx.fillText('WORKOUT', W / 2, 270);
    ctx.fillStyle = '#39ff14';
    ctx.fillText('COMPLETE', W / 2, 365);
    ctx.shadowBlur = 0;

    ctx.fillStyle = '#8fa39a';
    ctx.font = `500 34px ${body}`;
    ctx.fillText(`${data.title}  ·  ${data.date}`, W / 2, 430);

    const stats = [['DURATION', data.duration], ['VOLUME', `${Number(data.volume).toLocaleString()} kg`], ['SETS', String(data.sets)], ['XP', `+${data.xp}`]];
    stats.forEach(([label, value], i) => {
        const col = i % 2;
        const row = Math.floor(i / 2);
        const x = 90 + col * 456;
        const y = 500 + row * 190;
        ctx.fillStyle = 'rgba(57,255,20,0.07)';
        roundRect(ctx, x, y, 420, 160, 28);
        ctx.fill();
        ctx.strokeStyle = 'rgba(57,255,20,0.35)';
        ctx.lineWidth = 2;
        roundRect(ctx, x, y, 420, 160, 28);
        ctx.stroke();
        ctx.fillStyle = '#8fa39a';
        ctx.font = `600 24px ${body}`;
        ctx.fillText(label, x + 210, y + 52);
        ctx.fillStyle = '#39ff14';
        ctx.font = `700 54px ${display}`;
        ctx.fillText(value, x + 210, y + 118);
    });

    let y = 930;
    if (data.prs?.length) {
        ctx.fillStyle = '#39ff14';
        ctx.font = `700 30px ${display}`;
        ctx.fillText('PERSONAL RECORDS', W / 2, y);
        ctx.fillStyle = '#e8f5e9';
        ctx.font = `600 34px ${body}`;
        data.prs.forEach((pr, i) => ctx.fillText(pr, W / 2, y + 56 + i * 52));
        y += 60 + data.prs.length * 52 + 20;
    }

    ctx.fillStyle = '#e8f5e9';
    ctx.font = `700 40px ${display}`;
    ctx.fillText(`${data.streak}-DAY STREAK  ·  LEVEL ${data.level}`, W / 2, Math.max(y + 40, 1130));

    ctx.fillStyle = '#8fa39a';
    ctx.font = `500 28px ${body}`;
    ctx.fillText(`${data.name} trained with IronPulse`, W / 2, 1260);

    return new Promise((resolve) => canvas.toBlob((blob) => resolve(blob), 'image/png'));
}

/** Use the native share sheet with the image when possible, otherwise download it. */
export async function shareOrDownload(blob, filename = 'ironpulse-workout.png') {
    const file = new File([blob], filename, { type: 'image/png' });
    if (navigator.canShare?.({ files: [file] })) {
        try {
            await navigator.share({ files: [file], title: 'My IronPulse workout' });
            return 'shared';
        } catch (e) {
            if (e.name === 'AbortError') return 'cancelled';
        }
    }
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
    return 'downloaded';
}
