const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const root='C:/Projects/nikolacars',backup=root+'/.local-backup-20261030-services-content';
const inventory=JSON.parse(fs.readFileSync(root+'/docs/reports/services-seo-audit-2026-10-30-sources/rendered-inventory.json','utf8'));
const ledger=[];
function write(rel,text){const f=path.join(root,rel),exists=fs.existsSync(f),before=exists?fs.readFileSync(f):null;if(before?.toString('utf8')===text)return;const b=path.join(backup,'before',rel);fs.mkdirSync(path.dirname(b),{recursive:true});if(exists){if(fs.existsSync(b))throw Error('Backup exists '+rel);fs.writeFileSync(b,before);}fs.mkdirSync(path.dirname(f),{recursive:true});fs.writeFileSync(f,text);ledger.push({path:rel,existed:exists,before_sha256:before?crypto.createHash('sha256').update(before).digest('hex'):null,after_sha256:crypto.createHash('sha256').update(text).digest('hex')});}
function edit(rel,fn){write(rel,fn(fs.readFileSync(path.join(root,rel),'utf8')));}
const pairs={
'tesla-navigation-firmware':['Карти, GPS, пошук адрес і перевірка роботи навігації.','Карты, GPS, поиск адресов и проверка работы навигации.'],
'tesla-drive-unit-oil-repair':['Підбір рідини за приводним вузлом, заміна та перевірка герметичності.','Подбор жидкости по приводному узлу, замена и проверка герметичности.'],
'tesla-computer-diagnostics':['Перевірка помилок, батареї, заряджання та електроніки.','Проверка ошибок, батареи, зарядки и электроники.'],
'tesla-pre-purchase-inspection':['Огляд батареї, кузова, ходової та електроніки перед угодою.','Осмотр батареи, кузова, ходовой и электроники перед сделкой.'],
'tesla-tegra-intel-upgrade':['Перевірка сумісності та апгрейд мультимедіа Tegra на Intel.','Проверка совместимости и апгрейд мультимедиа Tegra на Intel.'],
'tesla-charge-port-repair':['Діагностика роз’єму, замка та причин помилок заряджання.','Диагностика разъёма, замка и причин ошибок зарядки.'],
'tesla-brake-pads-repair':['Перевірка зносу колодок і дисків та заміна сумісного комплекту.','Проверка износа колодок и дисков и замена совместимого комплекта.'],
'tesla-mcu-repair':['Діагностика зависань, перезавантажень і несправностей MCU.','Диагностика зависаний, перезагрузок и неисправностей MCU.'],
'tesla-antifreeze-replacement':['Перевірка контуру охолодження, заміна рідини та контроль витоків.','Проверка контура охлаждения, замена жидкости и контроль утечек.'],
'tesla-cabin-filter-replacement':['Підбір і заміна салонного фільтра за конфігурацією автомобіля.','Подбор и замена салонного фильтра по конфигурации автомобиля.'],
'tesla-horn-repair':['Перевірка звукового сигналу, його живлення та проводки.','Проверка звукового сигнала, его питания и проводки.'],
'tesla-camera-repair':['Діагностика зображення, проводки та сумісності камери.','Диагностика изображения, проводки и совместимости камеры.'],
'tesla-console-display-repair':['Перевірка екрана й тачскрина, ремонт або заміна за станом.','Проверка экрана и тачскрина, ремонт или замена по состоянию.'],
'tesla-model-s-contactor-repair':['Діагностика та заміна контакторів HV-батареї Model S.','Диагностика и замена контакторов HV-батареи Model S.'],
'tesla-wiper-replacement':['Підбір щіток, встановлення та перевірка роботи склоочисника.','Подбор щёток, установка и проверка работы стеклоочистителя.'],
'tesla-heater-repair':['Діагностика причин відсутності тепла та перевірка опалення.','Диагностика причин отсутствия тепла и проверка отопления.'],
'tesla-12v-battery-replacement':['Перевірка типу низьковольтної батареї та підбір сумісної заміни.','Проверка типа низковольтной батареи и подбор совместимой замены.'],
'tesla-headlight-drl-repair':['Діагностика фари, ближнього світла та денних ходових вогнів.','Диагностика фары, ближнего света и дневных ходовых огней.'],
'tesla-pyrofuse-replacement':['Діагностика пірозапобіжника та заміна за конфігурацією HV-батареї.','Диагностика пиропредохранителя и замена по конфигурации HV-батареи.'],
'tesla-panoramic-roof-replacement':['Огляд пошкодженого скляного даху та підбір сумісного елемента.','Осмотр повреждённой стеклянной крыши и подбор совместимого элемента.'],
'tesla-processor-repair':['Діагностика електронного блока та визначення причини його збою.','Диагностика электронного блока и определение причины его сбоя.'],
'tesla-parking-sensor-repair':['Перевірка датчиків, проводки та причин помилок Park Assist.','Проверка датчиков, проводки и причин ошибок Park Assist.'],
'tesla-model-s-battery-fuse':['Діагностика та заміна запобіжника HV-батареї Model S.','Диагностика и замена предохранителя HV-батареи Model S.'],
'tesla-radiator-repair':['Перевірка витоків, стану радіатора та контуру охолодження.','Проверка утечек, состояния радиатора и контура охлаждения.'],
'tesla-steering-rack-repair':['Діагностика стуків, люфту та причин порушення роботи керма.','Диагностика стуков, люфта и причин нарушения работы руля.'],
'tesla-control-arm-repair':['Перевірка важелів, шарнірів і суміжних вузлів підвіски.','Проверка рычагов, шарниров и смежных узлов подвески.'],
'tesla-windshield-repair':['Оцінка пошкодження, підбір скла та перевірка після встановлення.','Оценка повреждения, подбор стекла и проверка после установки.'],
'tesla-sim-card-repair':['Перевірка типу модуля зв’язку та можливості встановлення SIM.','Проверка типа модуля связи и возможности установки SIM.'],
'tesla-silentblock-repair':['Перевірка люфту й зносу сайлентблоків конкретного вузла.','Проверка люфта и износа сайлентблоков конкретного узла.'],
'tesla-hub-repair':['Діагностика гулу, вібрації та люфту маточини колеса.','Диагностика гула, вибрации и люфта ступицы колеса.'],
'tesla-inverter-repair':['Діагностика інвертора та погодження ремонту або заміни.','Диагностика инвертора и согласование ремонта или замены.'],
'tesla-air-strut-repair':['Перевірка витоків повітря, просідання кузова та пневмостійок.','Проверка утечек воздуха, проседания кузова и пневмостоек.'],
'tesla-suspension-repair':['Перевірка ходової та ремонт окремих вузлів підвіски.','Проверка ходовой и ремонт отдельных узлов подвески.'],
'tesla-window-regulator-repair':['Перевірка механізму, приводу та причин збою руху скла.','Проверка механизма, привода и причин сбоя движения стекла.'],
'tesla-control-unit-flash':['Перевірка конфігурації та адаптація блока після ремонту чи заміни.','Проверка конфигурации и адаптация блока после ремонта или замены.'],
'tesla-srs-crash':['Перевірка помилок SRS та стану системи після ДТП.','Проверка ошибок SRS и состояния системы после ДТП.'],
'tesla-body-crash':['Діагностика кузовної електроніки та проводки після ДТП.','Диагностика кузовной электроники и проводки после ДТП.'],
'tesla-battery-crash':['Діагностика високовольтної батареї та повідомлень після ДТП.','Диагностика высоковольтной батареи и сообщений после ДТП.'],
'tesla-air-suspension-crash':['Перевірка пневмопідвіски після ДТП на сумісному автомобілі.','Проверка пневмоподвески после ДТП на совместимом автомобиле.'],
'tesla-modem-repair':['Перевірка LTE, TCU, антен та причин відсутності мережі.','Проверка LTE, TCU, антенн и причин отсутствия сети.'],
'tesla-radar-calibration':['Перевірка конфігурації та потреби калібрування штатного радара.','Проверка конфигурации и необходимости калибровки штатного радара.'],
'tesla-camera-calibration':['Перевірка причини повідомлення та потрібної процедури калібрування.','Проверка причины сообщения и необходимой процедуры калибровки.'],
'tesla-key-flash':['Додавання ключа, перевірка сумісності та доступу власника.','Добавление ключа, проверка совместимости и доступа владельца.'],
'tesla-ccs-charge-port-repair':['Перевірка сумісності з CCS та потрібного обсягу переобладнання.','Проверка совместимости с CCS и необходимого объёма переоборудования.']};
const names={
'tesla-drive-unit-oil-repair':['Заміна мастила в редукторі Tesla','Замена масла в редукторе Tesla'],
'tesla-tegra-intel-upgrade':['Апгрейд Tesla Tegra → Intel','Апгрейд Tesla Tegra → Intel'],
'tesla-brake-pads-repair':['Заміна гальмівних колодок Tesla','Замена тормозных колодок Tesla'],
'tesla-sim-card-repair':['Встановлення та заміна SIM-картки Tesla','Установка и замена SIM-карты Tesla'],
'tesla-suspension-repair':['Ремонт підвіски Tesla','Ремонт подвески Tesla']};
const groups={
drive:['tesla-electricmotor-repair','tesla-drive-unit-oil-repair','tesla-inverter-repair','tesla-computer-diagnostics'],
hv:['tesla-battery-repair','tesla-pyrofuse-replacement','tesla-model-s-battery-fuse','tesla-model-s-contactor-repair','tesla-battery-crash'],
charge:['tesla-charge-port-repair','tesla-ccs-charge-port-repair','tesla-battery-repair','tesla-computer-diagnostics'],
media:['tesla-mcu-repair','tesla-console-display-repair','tesla-tegra-intel-upgrade','tesla-processor-repair','tesla-computer-diagnostics'],
connect:['tesla-sim-card-repair','tesla-modem-repair','tesla-mcu-repair','vidnovlennya-sertyfikativ-tesla'],
software:['firmware-auto','tesla-control-unit-flash','tesla-navigation-firmware','vidnovlennya-sertyfikativ-tesla','tesla-computer-diagnostics'],
chassis:['tesla-suspension-repair','tesla-control-arm-repair','tesla-silentblock-repair','tesla-hub-repair','tesla-steering-rack-repair'],
air:['tesla-air-strut-repair','tesla-suspension-repair','tesla-air-suspension-crash','tesla-computer-diagnostics'],
climate:['tesla-antifreeze-replacement','tesla-radiator-repair','tesla-heater-repair','tesla-cabin-filter-replacement','tesla-service'],
glass:['tesla-windshield-repair','tesla-camera-calibration','tesla-camera-repair','tesla-panoramic-roof-replacement'],
door:['repair-tesla-door-handle','tesla-window-regulator-repair','tesla-body-crash','tesla-computer-diagnostics'],
inspect:['tesla-service','tesla-pre-purchase-inspection','tesla-computer-diagnostics','tesla-battery-repair']};
const groupOf={};for(const [key,slugs] of Object.entries(groups))for(const s of slugs)if(!groupOf[s])groupOf[s]=key;
Object.assign(groupOf,{'tesla-navigation-firmware':'software','tesla-control-unit-flash':'software','tesla-tegra-intel-upgrade':'media','tesla-computer-diagnostics':'inspect','tesla-battery-repair':'hv','tesla-suspension-repair':'chassis','tesla-mcu-repair':'media','vidnovlennya-sertyfikativ-tesla':'software','tesla-service':'inspect','tesla-pre-purchase-inspection':'inspect'});
const explicit={
'tesla-service':['tesla-computer-diagnostics','tesla-pre-purchase-inspection','tesla-battery-repair','tesla-suspension-repair','tesla-charge-port-repair'],
'prigon-tesla-usa':['tesla-pre-purchase-inspection','tesla-service','tesla-navigation-firmware'],
'tesla-12v-battery-replacement':['tesla-computer-diagnostics','tesla-service','tesla-battery-repair'],
'tesla-brake-pads-repair':['tesla-service','tesla-suspension-repair','tesla-hub-repair'],
'tesla-wiper-replacement':['tesla-windshield-repair','tesla-service','tesla-camera-calibration'],
'tesla-horn-repair':['tesla-computer-diagnostics','tesla-control-unit-flash','tesla-service'],
'tesla-headlight-drl-repair':['tesla-body-crash','tesla-computer-diagnostics','tesla-service'],
'tesla-parking-sensor-repair':['tesla-camera-repair','tesla-computer-diagnostics','tesla-body-crash'],
'tesla-srs-crash':['tesla-computer-diagnostics','tesla-body-crash','tesla-service'],
'tesla-body-crash':['tesla-control-unit-flash','repair-tesla-door-handle','tesla-headlight-drl-repair','tesla-computer-diagnostics'],
'tesla-radar-calibration':['tesla-camera-calibration','tesla-computer-diagnostics','tesla-service'],
'tesla-key-flash':['tesla-computer-diagnostics','tesla-control-unit-flash','tesla-service'],
'tesla-subframe-repair':['tesla-suspension-repair','tesla-control-arm-repair','tesla-steering-rack-repair']};
const overrides={};
function fields(slug,uk,ru,descUk,descRu,h1Uk,h1Ru){overrides[slug]={};if(uk)Object.assign(overrides[slug],{meta_title_uk:uk,meta_title_ru:ru});if(descUk)Object.assign(overrides[slug],{meta_description_uk:descUk,meta_description_ru:descRu});if(h1Uk)Object.assign(overrides[slug],{name_uk:h1Uk,name_ru:h1Ru});}
fields('tesla-drive-unit-oil-repair','Заміна мастила в редукторі Tesla у Києві | NikolaCars','Замена масла в редукторе Tesla в Киеве | NikolaCars','Заміна мастила в редукторі Tesla у Києві. Визначимо тип приводного вузла, підберемо рідину та обсяг за його специфікацією, перевіримо герметичність після робіт.','Замена масла в редукторе Tesla в Киеве. Определим тип приводного узла, подберём жидкость и объём по его спецификации, проверим герметичность после работ.');
fields('tesla-pre-purchase-inspection','Перевірка Tesla перед купівлею у Києві | NikolaCars','Проверка Tesla перед покупкой в Киеве | NikolaCars','Перевірка Tesla перед купівлею у Києві: батарея, заряджання, кузов, ходова та електроніка. Перелік перевірок погодимо до огляду; пояснимо виявлені недоліки.','Проверка Tesla перед покупкой в Киеве: батарея, зарядка, кузов, ходовая и электроника. Перечень проверок согласуем до осмотра; объясним обнаруженные недостатки.');
fields('tesla-cabin-filter-replacement',null,null,'Заміна салонного фільтра Tesla у Києві. Перевіримо стан фільтра та підберемо сумісний комплект. Причину запаху чи слабкого обдуву уточнимо окремо.','Замена салонного фильтра Tesla в Киеве. Проверим состояние фильтра и подберём совместимый комплект. Причину запаха или слабого обдува уточним отдельно.','Заміна салонного фільтра Tesla','Замена салонного фильтра Tesla');
fields('tesla-sim-card-repair','Встановлення та заміна SIM-картки Tesla у Києві | NikolaCars','Установка и замена SIM-карты Tesla в Киеве | NikolaCars','Встановлення та заміна SIM-картки Tesla у Києві. Перевіримо тип модуля зв’язку та причину відсутності мережі. Можливість робіт визначимо за комплектацією.','Установка и замена SIM-карты Tesla в Киеве. Проверим тип модуля связи и причину отсутствия сети. Возможность работ определим по комплектации.',...names['tesla-sim-card-repair']);
fields('tesla-camera-calibration','Калібрування камери Tesla у Києві | NikolaCars','Калибровка камеры Tesla в Киеве | NikolaCars','Калібрування камери Tesla у Києві після заміни скла, камери або ремонту. Перевіримо причину повідомлення та потрібну процедуру для вашої конфігурації.','Калибровка камеры Tesla в Киеве после замены стекла, камеры или ремонта. Проверим причину сообщения и нужную процедуру для вашей конфигурации.');
fields('tesla-ccs-charge-port-repair',null,null,'Перевірка сумісності Tesla з CCS у Києві. Визначимо потрібні компоненти та обсяг переобладнання за конфігурацією авто. Ремонт зарядного порту — окрема послуга.','Проверка совместимости Tesla с CCS в Киеве. Определим нужные компоненты и объём переоборудования по конфигурации авто. Ремонт зарядного порта — отдельная услуга.');
const uaDescriptions={
'tesla-horn-repair':'Ремонт і заміна звукового сигналу Tesla у Києві. Перевіримо причину відсутності звуку, живлення та проводку; обсяг робіт погодимо після діагностики.',
'tesla-control-arm-repair':'Діагностика та заміна важелів підвіски Tesla у Києві. Перевіримо люфт, шарніри й суміжні вузли; підберемо рішення за станом автомобіля.',
'tesla-windshield-repair':'Ремонт і заміна лобового скла Tesla у Києві. Оцінимо пошкодження та сумісність скла, погодимо роботи й потребу перевірки камер після встановлення.',
'tesla-window-regulator-repair':'Ремонт і заміна склопідіймача Tesla у Києві. Перевіримо механізм, привід і причину збою; після робіт перевіримо рух та положення скла.',
'tesla-inverter-repair':'Діагностика, ремонт і заміна інвертора Tesla у Києві. Визначимо причину несправності та погодимо варіант робіт і вартість. NikolaCars.',
'tesla-air-suspension-crash':'Перевірка пневмопідвіски Tesla після ДТП у Києві: витоки, стійки, магістралі та датчики висоти. Склад робіт — за результатом огляду сумісного автомобіля.'};
for(const [s,v]of Object.entries(uaDescriptions))overrides[s]={...(overrides[s]||{}),meta_description_uk:v};
overrides['tesla-inverter-repair'].meta_description_ru='Диагностика, ремонт и замена инвертора Tesla в Киеве. Проверим причину неисправности и согласуем вариант работ и стоимость. NikolaCars.';
overrides['tesla-modem-repair']={meta_description_uk:'Ремонт модема Tesla у Києві. Діагностика LTE, TCU, SIM та антен; перевірка причин відсутності мережі й варіантів ремонту. NikolaCars.',meta_description_ru:'Ремонт модема Tesla в Киеве. Диагностика LTE, TCU, SIM и антенн; проверка причин отсутствия сети и вариантов ремонта. NikolaCars.'};
function php(v,indent=0){if(Array.isArray(v))return '['+v.map(x=>php(x,indent)).join(', ')+']';if(v&&typeof v==='object')return '[\n'+Object.entries(v).map(([k,x])=>' '.repeat(indent+4)+php(k)+' => '+php(x,indent+4)+',').join('\n')+'\n'+' '.repeat(indent)+']';return "'"+String(v).replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";}
const config={};
for(const r of inventory.filter(x=>x.path.startsWith('/services/')&&x.path!='/services/')){const s=r.path.split('/')[2],ru=inventory.find(x=>x.path==='/ru'+r.path);const name=names[s]||[r.h1[0].replace(/^Tesla:\s*/,''),ru.h1[0].replace(/^Tesla:\s*/,'')];const related=explicit[s]||(groups[groupOf[s]]||[]).filter(x=>x!==s);if(!related.length)throw Error('Missing related '+s);config[s]={name_uk:name[0],name_ru:name[1],related};if(pairs[s]){config[s].summary_uk=pairs[s][0];config[s].summary_ru=pairs[s][1];}if(overrides[s])config[s].overrides=overrides[s];}
write('config/service_content.php',"<?php\n\n// Editorial names, summaries and deliberately selected related services. Stable slugs are preserved.\nreturn "+php(config)+";\n");
edit('config/targeted_services.php',text=>{for(const [s,n]of Object.entries(config)){const line=new RegExp("(\\['slug' => '"+s+"'.*?'name_ru' => ')[^']*('.*?'name_uk' => ')[^']*('\\],)");text=text.replace(line,(_,a,b,c)=>a+n.name_ru.replace(/'/g,"\\'")+b+n.name_uk.replace(/'/g,"\\'")+c);}return text;});
for(const f of ['resources/views/services_index.blade.php','resources/views/home.blade.php'])edit(f,t=>t.replace(/\? 'Целевая услуга Tesla: «'[^\r\n]*\r?\n\s*: 'Цільова послуга Tesla: «'[^\r\n]*/g,"? config('service_content.' . $service['slug'] . '.summary_ru')\n              : config('service_content.' . $service['slug'] . '.summary_uk')"));
edit('resources/views/services/tesla-service.blade.php',t=>{
t=t.replace('  $serviceData = $serviceData ?? null;',"  $serviceData = $serviceData ?? null;\n  if (is_array($serviceData)) {\n    $serviceData = array_replace($serviceData, config('service_content.' . $serviceData['slug'] . '.overrides', []));\n  }");
const start=t.indexOf('  $relatedServices = collect();'),end=t.indexOf('\n@endphp',start);
t=t.slice(0,start)+`  $relatedServices = collect();
  if ($isTargetedPage) {
    $serviceContent = config('service_content', []);
    foreach (($serviceContent[$serviceData['slug']]['related'] ?? []) as $relatedSlug) {
      if (isset($serviceContent[$relatedSlug])) {
        $relatedServices->push(array_merge($serviceContent[$relatedSlug], ['slug' => $relatedSlug]));
      }
    }
  }`+t.slice(end);
t=t.replace("($serviceData['hero_subtitle_ru'] ?? ('Узкоспециализированный сервис Tesla по кейсу «' . $serviceData['name_ru'] . '» в Киеве.'))","($serviceData['hero_subtitle_ru'] ?? config('service_content.' . $serviceData['slug'] . '.summary_ru'))");
t=t.replace("($serviceData['hero_subtitle_uk'] ?? ('Вузькоспеціалізований сервіс Tesla за кейсом «' . $serviceData['name_uk'] . '» у Києві.'))","($serviceData['hero_subtitle_uk'] ?? config('service_content.' . $serviceData['slug'] . '.summary_uk'))");
return t;});
fs.mkdirSync(backup,{recursive:true});fs.writeFileSync(backup+'/manifest.json',JSON.stringify({status:'LOCAL_IN_PROGRESS',files:ledger},null,2));
console.log('Initial implementation '+ledger.length+' files; manifest '+backup+'/manifest.json');
