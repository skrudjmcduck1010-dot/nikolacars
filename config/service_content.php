<?php

// Explicit topical links. Names here are only for the eight non-targeted service pages.
return [
    'tesla-service' => [
        'related' => [
            'tesla-computer-diagnostics',
            'tesla-pre-purchase-inspection',
            'tesla-battery-repair',
            'tesla-suspension-repair',
            'tesla-charge-port-repair',
        ],
        'name_uk' => 'Діагностика, ремонт та обслуговування Tesla у Києві',
        'name_ru' => 'Диагностика, ремонт и обслуживание Tesla в Киеве',
    ],
    'tesla-electricmotor-repair' => [
        'related' => [
            'tesla-drive-unit-oil-repair',
            'tesla-inverter-repair',
            'tesla-computer-diagnostics',
        ],
        'name_uk' => 'Ремонт електромотора Tesla Model S у Києві',
        'name_ru' => 'Ремонт электромотора Tesla Model S в Киеве',
    ],
    'tesla-battery-repair' => [
        'related' => [
            'tesla-pyrofuse-replacement',
            'tesla-model-s-battery-fuse',
            'tesla-model-s-contactor-repair',
            'tesla-battery-crash',
        ],
        'name_uk' => 'Ремонт батарей Tesla у Києві',
        'name_ru' => 'Ремонт батарей Tesla в Киеве',
    ],
    'repair-tesla-door-handle' => [
        'related' => [
            'tesla-window-regulator-repair',
            'tesla-body-crash',
            'tesla-computer-diagnostics',
        ],
        'name_uk' => 'Ремонт / заміна ручки Tesla у Києві',
        'name_ru' => 'Ремонт / замена ручки Tesla в Киеве',
    ],
    'tesla-subframe-repair' => [
        'related' => [
            'tesla-suspension-repair',
            'tesla-control-arm-repair',
            'tesla-steering-rack-repair',
        ],
        'name_uk' => 'Заміна та ремонт підрамника Tesla у Києві',
        'name_ru' => 'Замена и ремонт подрамника Tesla в Киеве',
    ],
    'vidnovlennya-sertyfikativ-tesla' => [
        'related' => [
            'firmware-auto',
            'tesla-control-unit-flash',
            'tesla-navigation-firmware',
            'tesla-computer-diagnostics',
        ],
        'name_uk' => 'Відновлення сертифікатів Tesla на нашому СТО',
        'name_ru' => 'Восстановление сертификатов Tesla на нашем СТО',
    ],
    'firmware-auto' => [
        'related' => [
            'tesla-control-unit-flash',
            'tesla-navigation-firmware',
            'vidnovlennya-sertyfikativ-tesla',
            'tesla-computer-diagnostics',
        ],
        'name_uk' => 'Оновлення прошивки Tesla',
        'name_ru' => 'Обновление прошивки Tesla',
    ],
    'prigon-tesla-usa' => [
        'related' => [
            'tesla-pre-purchase-inspection',
            'tesla-service',
            'tesla-navigation-firmware',
        ],
        'name_uk' => 'Пригін Tesla із США',
        'name_ru' => 'Пригон Tesla из США',
    ],
    'tesla-navigation-firmware' => [
        'related' => [
            'firmware-auto',
            'tesla-control-unit-flash',
            'vidnovlennya-sertyfikativ-tesla',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-drive-unit-oil-repair' => [
        'related' => [
            'tesla-electricmotor-repair',
            'tesla-inverter-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-computer-diagnostics' => [
        'related' => [
            'tesla-service',
            'tesla-pre-purchase-inspection',
            'tesla-battery-repair',
        ],
    ],
    'tesla-pre-purchase-inspection' => [
        'related' => [
            'tesla-service',
            'tesla-computer-diagnostics',
            'tesla-battery-repair',
        ],
    ],
    'tesla-tegra-intel-upgrade' => [
        'related' => [
            'tesla-mcu-repair',
            'tesla-console-display-repair',
            'tesla-processor-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-charge-port-repair' => [
        'related' => [
            'tesla-ccs-charge-port-repair',
            'tesla-battery-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-brake-pads-repair' => [
        'related' => [
            'tesla-service',
            'tesla-suspension-repair',
            'tesla-hub-repair',
        ],
    ],
    'tesla-mcu-repair' => [
        'related' => [
            'tesla-console-display-repair',
            'tesla-tegra-intel-upgrade',
            'tesla-processor-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-antifreeze-replacement' => [
        'related' => [
            'tesla-radiator-repair',
            'tesla-heater-repair',
            'tesla-cabin-filter-replacement',
            'tesla-service',
        ],
    ],
    'tesla-cabin-filter-replacement' => [
        'related' => [
            'tesla-antifreeze-replacement',
            'tesla-radiator-repair',
            'tesla-heater-repair',
            'tesla-service',
        ],
    ],
    'tesla-horn-repair' => [
        'related' => [
            'tesla-computer-diagnostics',
            'tesla-control-unit-flash',
            'tesla-service',
        ],
    ],
    'tesla-camera-repair' => [
        'related' => [
            'tesla-windshield-repair',
            'tesla-camera-calibration',
            'tesla-panoramic-roof-replacement',
        ],
    ],
    'tesla-console-display-repair' => [
        'related' => [
            'tesla-mcu-repair',
            'tesla-tegra-intel-upgrade',
            'tesla-processor-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-model-s-contactor-repair' => [
        'related' => [
            'tesla-battery-repair',
            'tesla-pyrofuse-replacement',
            'tesla-model-s-battery-fuse',
            'tesla-battery-crash',
        ],
    ],
    'tesla-wiper-replacement' => [
        'related' => [
            'tesla-windshield-repair',
            'tesla-service',
            'tesla-camera-calibration',
        ],
    ],
    'tesla-heater-repair' => [
        'related' => [
            'tesla-antifreeze-replacement',
            'tesla-radiator-repair',
            'tesla-cabin-filter-replacement',
            'tesla-service',
        ],
    ],
    'tesla-12v-battery-replacement' => [
        'related' => [
            'tesla-computer-diagnostics',
            'tesla-service',
            'tesla-battery-repair',
        ],
    ],
    'tesla-headlight-drl-repair' => [
        'related' => [
            'tesla-body-crash',
            'tesla-computer-diagnostics',
            'tesla-service',
        ],
    ],
    'tesla-pyrofuse-replacement' => [
        'related' => [
            'tesla-battery-repair',
            'tesla-model-s-battery-fuse',
            'tesla-model-s-contactor-repair',
            'tesla-battery-crash',
        ],
    ],
    'tesla-panoramic-roof-replacement' => [
        'related' => [
            'tesla-windshield-repair',
            'tesla-camera-calibration',
            'tesla-camera-repair',
        ],
    ],
    'tesla-processor-repair' => [
        'related' => [
            'tesla-mcu-repair',
            'tesla-console-display-repair',
            'tesla-tegra-intel-upgrade',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-parking-sensor-repair' => [
        'related' => [
            'tesla-camera-repair',
            'tesla-computer-diagnostics',
            'tesla-body-crash',
        ],
    ],
    'tesla-model-s-battery-fuse' => [
        'related' => [
            'tesla-battery-repair',
            'tesla-pyrofuse-replacement',
            'tesla-model-s-contactor-repair',
            'tesla-battery-crash',
        ],
    ],
    'tesla-radiator-repair' => [
        'related' => [
            'tesla-antifreeze-replacement',
            'tesla-heater-repair',
            'tesla-cabin-filter-replacement',
            'tesla-service',
        ],
    ],
    'tesla-steering-rack-repair' => [
        'related' => [
            'tesla-suspension-repair',
            'tesla-control-arm-repair',
            'tesla-silentblock-repair',
            'tesla-hub-repair',
        ],
    ],
    'tesla-control-arm-repair' => [
        'related' => [
            'tesla-suspension-repair',
            'tesla-silentblock-repair',
            'tesla-hub-repair',
            'tesla-steering-rack-repair',
        ],
    ],
    'tesla-windshield-repair' => [
        'related' => [
            'tesla-camera-calibration',
            'tesla-camera-repair',
            'tesla-panoramic-roof-replacement',
        ],
    ],
    'tesla-sim-card-repair' => [
        'related' => [
            'tesla-modem-repair',
            'tesla-mcu-repair',
            'vidnovlennya-sertyfikativ-tesla',
        ],
    ],
    'tesla-silentblock-repair' => [
        'related' => [
            'tesla-suspension-repair',
            'tesla-control-arm-repair',
            'tesla-hub-repair',
            'tesla-steering-rack-repair',
        ],
    ],
    'tesla-hub-repair' => [
        'related' => [
            'tesla-suspension-repair',
            'tesla-control-arm-repair',
            'tesla-silentblock-repair',
            'tesla-steering-rack-repair',
        ],
    ],
    'tesla-inverter-repair' => [
        'related' => [
            'tesla-electricmotor-repair',
            'tesla-drive-unit-oil-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-air-strut-repair' => [
        'related' => [
            'tesla-suspension-repair',
            'tesla-air-suspension-crash',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-suspension-repair' => [
        'related' => [
            'tesla-control-arm-repair',
            'tesla-silentblock-repair',
            'tesla-hub-repair',
            'tesla-steering-rack-repair',
        ],
    ],
    'tesla-window-regulator-repair' => [
        'related' => [
            'repair-tesla-door-handle',
            'tesla-body-crash',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-control-unit-flash' => [
        'related' => [
            'firmware-auto',
            'tesla-navigation-firmware',
            'vidnovlennya-sertyfikativ-tesla',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-srs-crash' => [
        'related' => [
            'tesla-computer-diagnostics',
            'tesla-body-crash',
            'tesla-service',
        ],
    ],
    'tesla-body-crash' => [
        'related' => [
            'tesla-control-unit-flash',
            'repair-tesla-door-handle',
            'tesla-headlight-drl-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-battery-crash' => [
        'related' => [
            'tesla-battery-repair',
            'tesla-pyrofuse-replacement',
            'tesla-model-s-battery-fuse',
            'tesla-model-s-contactor-repair',
        ],
    ],
    'tesla-air-suspension-crash' => [
        'related' => [
            'tesla-air-strut-repair',
            'tesla-suspension-repair',
            'tesla-computer-diagnostics',
        ],
    ],
    'tesla-modem-repair' => [
        'related' => [
            'tesla-sim-card-repair',
            'tesla-mcu-repair',
            'vidnovlennya-sertyfikativ-tesla',
        ],
    ],
    'tesla-radar-calibration' => [
        'related' => [
            'tesla-camera-calibration',
            'tesla-computer-diagnostics',
            'tesla-service',
        ],
    ],
    'tesla-camera-calibration' => [
        'related' => [
            'tesla-windshield-repair',
            'tesla-camera-repair',
            'tesla-panoramic-roof-replacement',
        ],
    ],
    'tesla-key-flash' => [
        'related' => [
            'tesla-computer-diagnostics',
            'tesla-control-unit-flash',
            'tesla-service',
        ],
    ],
    'tesla-ccs-charge-port-repair' => [
        'related' => [
            'tesla-charge-port-repair',
            'tesla-battery-repair',
            'tesla-computer-diagnostics',
        ],
    ],
];
