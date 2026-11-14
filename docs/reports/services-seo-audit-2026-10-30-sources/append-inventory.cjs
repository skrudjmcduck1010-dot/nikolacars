const fs=require('node:fs');const path=require('node:path');
const dir=__dirname,report=path.resolve(dir,'../services-seo-audit-2026-10-30.md');
const data=JSON.parse(fs.readFileSync(path.join(dir,'rendered-inventory.json'),'utf8'));
const gsc=JSON.parse(fs.readFileSync(path.resolve(dir,'../gsc-priorities-2026-10-30-sources/comparison.json'),'utf8'));
const actions={
'tesla-service':['P1','Сохранить Title/H1/description. Хаб→диагностика/осмотр/батарея/зарядка/подвеска. UA474показа/позиция26,1 — сначала разрез запросов, не слепое увеличение текста.'],
'tesla-electricmotor-repair':['P2','Сохранить ModelS-Title/H1/description и границы модели; ссылки на масло/инвертор/диагностику. Условия гарантии не расширять.'],
'tesla-battery-repair':['Контроль','Недавно обновлена; сохранить Title/H1/description. Проверить индекс/запросы и адресную ссылку на диагностику, не повторять пакет.'],
'repair-tesla-door-handle':['P3','Title/H1/description достаточно конкретны. Сохранить; ссылка на стеклоподъёмник/Body/диагностику. В тексте не распространять механизм выдвижной ручки на все модели.'],
'tesla-subframe-repair':['P3','Метаданные сохранить. Ссылки подвеска/рычаги; ремонт vs замена по фактическому дефекту, без обещания восстановления любой геометрии.'],
'vidnovlennya-sertyfikativ-tesla':['Контроль','Свежий пакет сохранить. Сверить подписи главной «официальные решения» с фактическим каналом; не обещать App/обновления всем.'],
'firmware-auto':['P1','Заменить description про подбор/доставку, сократить Title, убрать обещание «без рисков», ссылки firmware/nav/certificates по назначению.'],
'prigon-tesla-usa':['Контроль','Свежий Title/description/контент оставить. Исправить нижнюю ссылку «СТО Tesla Киев» с главной на /services/tesla-service/ по локали. Новые ставки/сроки не добавлять.'],
'tesla-navigation-firmware':['Контроль','Сохранить новый смысл карты/GPS и запрет обещаний FSD; запросы/сниппет после выпуска, тематические ссылки.'],
'tesla-drive-unit-oil-repair':['P1','Карточка «ремонт масла»→замена; индивидуальные descriptions UA/RU; убрать общий hero/повтор после контактов и несопоставленные объёмы, тематические ссылки.'],
'tesla-computer-diagnostics':['P1','Title/H1/description уже конкретны. Хаб и LV должны ссылаться прямо на диагностику, а не общий сервис.'],
'tesla-pre-purchase-inspection':['P2','Сократить повторяющийся Title; description/результат осмотра; отдельное назначение от компьютерной диагностики.'],
'tesla-tegra-intel-upgrade':['P2','Карточка→апгрейд; убрать редакторские фразы «для страницы». Title/H1/description и успешный контент сохранить, связи MCU/дисплей.'],
'tesla-charge-port-repair':['P1','Конкретные метаданные сохранить; убрать редакторскую фразу и hero «кейс». Ссылки на CCS/батарею/диагностику с различиями назначения.'],
'tesla-brake-pads-repair':['P2','Карточка «ремонт колодок»→замена; detail Title/H1/description сохранить, links подвеска/диагностика по смыслу, без случайных8.'],
'tesla-mcu-repair':['P2','Текущие поля сохранить. Явные ссылки и границы MCU/дисплей/Tegra/processor; не дублировать все4текста.'],
'tesla-antifreeze-replacement':['P3','Конкретные Title/H1/description сохранить; ссылки радиатор/батарея/сервис, без универсального интервала замены.'],
'tesla-cabin-filter-replacement':['P1','Description с условным результатом, не гарантировать устранение запаха фильтром; отличие HEPA, links HVAC/сервис.'],
'tesla-horn-repair':['P2','Индивидуальный UA description; Title/H1 и RUсниппет сохранить. Проверить формулировку «ремонт» по фактическим работам.'],
'tesla-camera-repair':['P3','Метаданные сохранить; явные ссылки калибровка/лобовое/диагностика. Не обещать результат автопилота заменой камеры.'],
'tesla-console-display-repair':['P2','Метаданные сохранить. Отделить физический экран/тач от MCU, ссылки MCU/Tegra/диагностика.'],
'tesla-model-s-contactor-repair':['P2','Карточка только ModelS; detail Title/H1/description сохранить. Батарея/предохранитель/pyrofuse с различиями.'],
'tesla-wiper-replacement':['P3','Метаданные/контент оставить; links лобовое/сервис. В сохранённом срезе нет строк — не считать нулевым спросом.'],
'tesla-heater-repair':['P3','Метаданные оставить; разграничить heater/PTC и другие причины отсутствия тепла по конфигурации, links диагностика/антифриз.'],
'tesla-12v-battery-replacement':['Контроль','Недавний LVпакет сохранить. Индекс/CTR, точная ссылка диагностики, не смешивать с HV.'],
'tesla-headlight-drl-repair':['P3','Конкретные metadata оставить; не распространять «лампа ближнего» на все типы фары. Ссылки Body/диагностика; точный тип по комплектации.'],
'tesla-pyrofuse-replacement':['P2','Сохранить поля до query→pages; ссылки HV/fuse/контакторы, отличие узлов. Низкий CTR — основание исследования, не автоматическая смена Title.'],
'tesla-panoramic-roof-replacement':['P3','Конкретные поля сохранить; кратко уточнить состав стеклянного элемента по модели, links лобовое/сервис.'],
'tesla-processor-repair':['Уточнить','Сохранить до подтверждения реальной работы с платой/CPU/eMMC. Разделить с MCU/Tegra; не удалять/301 без querymap.'],
'tesla-parking-sensor-repair':['P3','Поля сохранить; наличие ультразвуковых датчиков по комплектации, не все ParkAssist=датчик. Ссылки диагностика/камеры.'],
'tesla-model-s-battery-fuse':['P2','Карточка только ModelS; удалить один одинаковый H2 «Що саме ми замінюємо…». H1 один и корректен; metadata сохранить.'],
'tesla-radiator-repair':['P3','Поля сохранить; links антифриз/диагностика, контур определяется по конфигурации.'],
'tesla-steering-rack-repair':['P3','Метаданные сохранить; links подвеска/рычаги/диагностика. Не считать любой стук доказательством рейки.'],
'tesla-control-arm-repair':['P2','Индивидуальный UA description; корректность «ремонта рычага» подтвердить, links подвеска/сайлентблоки.'],
'tesla-windshield-repair':['P2','Индивидуальный UA description; links калибровкакамер/сервис, ремонтопригодность по осмотру.'],
'tesla-sim-card-repair':['P1','Название установка/замена, индивидуальный description без обещания всех онлайнфункций. Разделить с модемом/сертификатами.'],
'tesla-silentblock-repair':['P3','Поля сохранить; links рычаги/подвеска. Совместимость и способ замены по конкретному узлу.'],
'tesla-hub-repair':['P3','Поля сохранить; links подвеска/диагностика/рычаги. Убрать редакторский оборот «для страницы» при точечной правке, не переписывать статью.'],
'tesla-inverter-repair':['P1','Владелец подтвердил услугу. Индивидуальный UA description/RUточечный сниппет и ссылки мотор/диагностика; одинH1 уже есть.'],
'tesla-air-strut-repair':['P3','Поля сохранить; links подвеска/пневмоCrash. Перечень моделей только с соответствующей комплектацией, без универсальности.'],
'tesla-suspension-repair':['P2','Поля сохранить; хабподвески→рычаги/сайлентблоки/ступица/пневмостойка/рейка. «Замена подвески» конкретизировать как замена узлов в карточке.'],
'tesla-window-regulator-repair':['P2','Индивидуальный UA description; fields остальное сохранить, links ручка/Body/диагностика.'],
'tesla-control-unit-flash':['P2','Поля достаточно конкретны; links общаяпрошивка/MCU/диагностика, разделить модульную адаптацию и общую прошивку.'],
'tesla-srs-crash':['P2/уточнить','Человеческие Title/H1 и назначение после ДТП; Crash вторичен, состав/результат подтвердить. Не обещать безопасность по удалениюDTC.'],
'tesla-body-crash':['P2/уточнить','Человеческие Title/H1: кузовнаяэлектроникапослеДТП, не кузовнойремонт. Links к фактическим электрическим услугам.'],
'tesla-battery-crash':['P2/уточнить','Человеческие Title/H1: диагностикаHVпослеДТП; links battery/pyrofuse, не повторять всюbatteryrepair.'],
'tesla-air-suspension-crash':['P2/уточнить','Человеческие поля послеДТП; UAdescription без всехмоделей; links airstrut/suspension, подтвердить фактический сценарий.'],
'tesla-modem-repair':['P1','Конкретные поля сохранить, снять безусловное обещание онлайнфункций; границы SIM/модем/MCU, тематические ссылки.'],
'tesla-radar-calibration':['P3','Поля сохранить; проверка наличия/типа радара по конфигурации. Links camera-calibration/диагностика; FSD не обещать.'],
'tesla-camera-calibration':['P1','Индивидуальный UAdescription/городTitle; удалить общийUAхвост2–6недель (локально подтверждено), links камера/стекло.'],
'tesla-key-flash':['Контроль','Свежий пакет сохранить: карта/KeyFob/PhoneKey/всеутрачены, подтверждение владения. Индекс/queries, без новой страницыдубля.'],
'tesla-ccs-charge-port-repair':['P1','Удалить liveподтверждённый UAобщийхвост2–6недель; индивидуальный UAdescription, не переписывать хорошийRU. Links порт/HV/диагностика.'],
'':['P1','Все52карточки синхронизировать с реальными именами/назначениями;44genericописания заменить индивидуальными. Title/H1/descriptionхаба оставить.']
};
let text='| Услуга / slug | GSC UA | GSC RU | Решение |\n|---|---|---|---|\n';
for(const r of data.filter(x=>!x.path.startsWith('/ru/'))){const slug=r.path.split('/')[2]||'';const a=actions[slug];if(!a)throw new Error('Missing '+slug);const metrics=p=>{const row=gsc.pages.find(x=>x[0]==='https://nikolacars.kiev.ua'+p);return row?[1,4,7,10].map(i=>row[i]).join(' / '):'нет в срезе';};text+=`| ${slug||'INDEX'} | ${metrics(r.path)} | ${metrics('/ru'+r.path)} | **${a[0]}** — ${a[1]} |\n`;}
fs.appendFileSync(report,text,'utf8');
const errors=JSON.parse(fs.readFileSync(path.join(dir,'render-checks.json'),'utf8'));
if(Object.entries(errors).some(([k,v])=>k!=='routes'&&v.length))throw new Error('Render contract issues');
console.log('Full matrix '+Object.keys(actions).length+' entries appended; render checks PASS.');
