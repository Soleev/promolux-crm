const clock = document.querySelector('#clock');
const start = performance.now();
const epoch = Number(clock.dataset.epoch);
const format = new Intl.DateTimeFormat('ru-RU', {timeZone:'Asia/Tashkent',dateStyle:'short',timeStyle:'medium'});
setInterval(() => { clock.textContent = format.format(new Date(epoch + performance.now() - start)); }, 1000);
const status = document.querySelector('#connection');
const content = document.querySelector('#sfp-content');
let busy = false;
async function refresh() {
    if (busy) return;
    busy = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
        const url = new URL(location.href); url.searchParams.set('fragment','1');
        const response = await fetch(url, {headers:{Accept:'text/html'},cache:'no-store',signal:controller.signal});
        if (response.redirected || [401,403,404,419].includes(response.status)) {
            content.replaceChildren();
            status.textContent = 'Просмотр недоступен. Войдите в CRM и откройте экран заново.';
            return;
        }
        if (!response.ok) throw new Error('unavailable');
        const html = await response.text();
        content.innerHTML = html;
        status.textContent = 'Данные обновлены · ' + new Intl.DateTimeFormat('ru-RU',{timeZone:'Asia/Tashkent',timeStyle:'medium'}).format(new Date());
    } catch {
        status.textContent = 'Нет связи с сервером. Показаны ранее полученные данные; повторяем подключение.';
    } finally { clearTimeout(timeout); busy = false; }
}
setInterval(refresh, 30000);
window.addEventListener('online',refresh);
window.addEventListener('offline',()=>{status.textContent='Нет сети. Данные могут быть устаревшими.';});
document.querySelector('#fullscreen').addEventListener('click',async()=>{
    try { if (!document.fullscreenElement) await document.documentElement.requestFullscreen(); else await document.exitFullscreen(); }
    catch { status.textContent='Полноэкранный режим недоступен в этом браузере.'; }
});
