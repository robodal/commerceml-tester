<?php

/**
 * Class CommerceML
 *
 * Расширение SimpleXMLElement методом заполнения структуры из массива, шаблоном документов CommerceML2 и
 * возможностью сохранения в любой кодировке
 */
class CommerceMLElement extends SimpleXMLElement {
    
    /**
     * Вызов родительского конструктора с установкой шаблонна документа CommerceML2
     * @param int $options
     * @param bool $data_is_url
     * @param string $ns
     * @param bool $is_prefix
     * @return CommerceMLElement
     */
    public static function getInstance ($options = 0, $data_is_url = false, $ns = "", $is_prefix = false) {
        return new self(implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n",
            '<КоммерческаяИнформация xmlns="urn:1C.ru:commerceml_2" ',
            'xmlns:xs="http://www.w3.org/2001/XMLSchema" ',
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ',
            'ВерсияСхемы="2.08" ',
            'ДатаФормирования="' . preg_replace('#\+.*$#', '', date('c')) . '"></',
            'КоммерческаяИнформация>',
        ]), $options, $data_is_url, $ns, $is_prefix);
    }
    
    
    /**
     * Рекурсивно генерирует дерево элементов XML на основе переданного массива.
     * Аттрибуты начинаются с символа @, набор элементов - вектор, вложенность ассоциативный массив
     * @param array $data
     * @return CommerceMLElement
     */
    public function addFromArray (array $data) {
        $child = $this;
        foreach ($data as $key => $val) {
            if ($key == '#') continue;
            $key = preg_replace('#:enum\d+$#', '', $key);
            if (substr($key, 0, 1) == '@') {
                $this->addAttribute(substr($key, 1), (string)$val);
                continue;
            }
            if (!is_array($val)) {
                $this->addChild($key, (string)$val);
                continue;
            }
            if (!isset($val[0])) {
                $child = $this->addChild($key, $val['#'] ?? '');
                $child->addFromArray($val);
                continue;
            }
            for ($i = 0; isset($val[$i]) && is_array($val[$i]); $i++) {
                $child = $this->addChild($key);
                $child->addFromArray($val[$i]);
            }
        }
        return $child;
    }
    
    
    /**
     * Выгрузка XML в строку или файл с указанием кодировки
     * @param string $encoding
     * @param string $file
     * @return string
     */
    public function asEncodingXML (string $encoding = 'utf-8', string $file = '') {
        $data = $this->asXML();
        if ($encoding != 'utf-8') $data = iconv('utf-8', $encoding, $data);
        $head = '<?xml version="1.0" encoding="' . $encoding . '" standalone="yes"?>';
        $data = preg_replace('#<\?xml[^>]+>#', $head, $data);
        if ($file) file_put_contents($file, $data);
        return $data;
    }
    
}

/**
 * Class CML2Generator
 * Генерация обменных файлов со случайными данными
 *
 */
class CML2Generator {
    
    /**
     * Экземпляр
     * @var CML2Generator
     */
    private static $instance;

    /**
     * Параметры генерации
     * @var array
     */
    private $config;

    /**
     * GUID классификатора
     * @var string
     */
    private $guidClassifier;
    
    /**
     * GUID каталога
     * @var string
     */
    private $guidCatalog;
    
    /**
     * GUID сгенерированных категорий
     * @var array
     */
    private $guidGroups;
    
    /**
     * GUID сгенерированных товаров
     * @var array
     */
    private $guidProds;
    
    /**
     * Сгенерированные единицы измерения
     * @var array
     */
    private $idUnits;
    
    /**
     * Загруженные словари генератора наименований
     * @var array
     */
    private $dictionaries;
    
    /**
     * Набор имеющихся фото
     * @var array
     */
    private $images;

    /**
     * CML2Generator constructor.
     * @param array $config
     * @throws Exception
     */
    private function __construct (array $config = []) {
        $this->config = array_merge([
            'categories-count' => 50,
            'categories-level' => 3,
            'products-count' => 450,
            'units-count' => 20,
            'export-path' => 'templates/',
            'images-path' => 'templates/images/',
            'dictionaries-path' => 'templates/',
        ], $config);
        $this->guidCatalog = $this->guid();
        $this->guidClassifier = $this->guid();
    }
    
    /**
     * Генерация GUID
     * @return string
     * @throws Exception
     */
    private function guid () {
        if ($guid = @file_get_contents('/proc/sys/kernel/random/uuid')) return trim($guid);
        $bytes = @random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
    
    /**
     * Загрузка словарей
     */
    private function loadDictionaries () {
        $path = $this->config['dictionaries-path'];
        $this->dictionaries = [
            'b' => array_map('trim', file($path . 'words-base.txt', FILE_SKIP_EMPTY_LINES)),
            'a' => array_map('trim', file($path . 'words-adjective.txt', FILE_SKIP_EMPTY_LINES)),
            'w' => array_map('trim', file($path . 'words-of-which.txt', FILE_SKIP_EMPTY_LINES)),
        ];
    }
    
    /**
     * Получение случайного слова из словаря
     * @param $type
     * @return string
     */
    private function getWord ($type) {
        if (!isset($this->dictionaries[$type])) return '';
        return $this->dictionaries[$type][array_rand($this->dictionaries[$type])];
    }
    
    /**
     * Приведение прилагательного к нужному роду
     * @param string $word
     * @param string $sex
     * @return string
     */
    private function adjectToSex (string $word, string $sex) {
        $sexs = explode('/', $word);
        if ($sex == 'm') return $sexs[0];
        $sexs['f'] = $sexs[1] ?? 'ая';
        $sexs['i'] = $sexs[2] ?? 'ое';
        return mb_substr($sexs[0], 0, -mb_strlen($sexs[$sex], 'utf8')) . $sexs[$sex];
    }
    
    /**
     * Генерация фразы по указанной структуре
     *   a - прилагательное, может встречаться до двух раз
     *   b - базовое существительное, обязательно встречается в структуре 1 раз
     *   w - уточняющее существительное "кого" (например, мешок МУКИ)
     * @param string $struct
     * @return string
     */
    private function genPhrase (string $struct = 'abw') {
        if (!is_array($this->dictionaries)) $this->loadDictionaries();
        list($wordBase, $sexBase) = explode('/', $this->getWord('b'));
        $wordsAdject = [
            $this->adjectToSex($this->getWord('a'), $sexBase),
            $this->adjectToSex($this->getWord('a'), $sexBase),
        ];
        $wordWhich = $this->getWord('w');
        $struct = str_split($struct);
        $phrase = [];
        foreach ($struct as $type) {
            switch ($type) {
                case 'b':
                    $phrase[] = $wordBase;
                    break;
                case 'a':
                    $phrase[] = array_pop($wordsAdject);
                    break;
                case 'w':
                    $phrase[] = $wordWhich;
                    break;
            }
        }
        $phrase = implode(' ', $phrase);
        return  mb_strtoupper(mb_substr($phrase, 0, 1, 'utf8'), 'utf8') .
                mb_strtolower(mb_substr($phrase, 1, null, 'utf8'), 'utf8');
    }
    
    /**
     * Генерация фразы по случайной структуре
     * @return string
     */
    private function genRandPhrase () {
        $structs = [
            'b' => 1,
            'bw' => 15,
            'wb' => 10,
            'ab' => 20,
            'ba' => 15,
            'abw' => 30,
            'aab' => 30,
            'bwa' => 20,
            'aabw' => 25,
        ];
        $rand = rand() / getrandmax() * array_sum($structs);
        $sum = 0;
        foreach ($structs as $struct => $weight) {
            $sum += $weight;
            if ($sum > $rand) return $this->genPhrase($struct);
        }
        return $this->genPhrase();
    }
    
    /**
     * Генерация категорий товаров
     * @param int $count
     * @param int $level
     * @return array
     * @throws Exception
     */
    private function genGroups (int $count, int $level) {
        if (!$level) return ['Группы' => ''];
        if ($count > 0) $count = -pow($count, 1 / $level);
        $groups = [];
        for ($i = round(abs((0.8 + rand() / getrandmax() * 0.4) * $count)); $i--;) {
            $guid = $this->guid();
            if ($level == 1) $this->guidGroups[] = $guid;
            $groups[] = array_merge([
                'Ид' => $guid,
                'ПометкаУдаления' => 'false',
                'Наименование' => $this->genRandPhrase(),
                'Описание' => '',
                'БитриксСортировка' => '999999',
            ], $this->genGroups($count, $level - 1));
        }
        return ['Группы' => ['Группа' => $groups]];
    }
    
    /**
     * Генерация единиц измерения
     * @param int $count
     * @return array
     */
    private function genUnits (int $count) {
        $units = [];
        for ($i = $count; $i--;) {
            $name = $this->genPhrase('b');
            $short = mb_strtolower(trim(mb_substr($name, 0, round(rand() / getrandmax() * 3 + 2), 'utf8')), 'utf8');
            $this->idUnits[] = [
                'name' => $name,
                'short' => $short,
                'id' => 750 + $i,
            ];
            $units[] = [
                'Ид' => 750 + $i,
                'Код' => 750 + $i,
                'ПометкаУдаления' => 'false',
                'НаименованиеПолное' => $name,
                'НаименованиеКраткое' => $short,
            ];
        }
        return ['ЕдиницыИзмерения' => ['ЕдиницаИзмерения' => $units]];
    }
    
    
    /**
     * Загрузка списка имеющихся иображений
     */
    private function loadImages () {
        $this->images = [];
        $dir = opendir($this->config['images-path']);
        while ($file = readdir($dir)) if (preg_match('#\.jpg#i', $file)) $this->images[] = $file;
        closedir($dir);
    }
    
    /**
     * Получить от 0 до 3 случайных картинок
     * @return array
     */
    private function getRndImages () {
        $images = [];
        if (count($this->images)) {
            for ($i = rand(0, 3); $i--;) $images['Картинка:enum' . $i] = $this->images[array_rand($this->images)];
        }
        return $images;
    }
    
    /**
     * Генерация списка товаров
     * @param int $count
     * @return array
     * @throws Exception
     */
    private function genCatalog (int $count) {
        $this->loadImages();
        $prods = [];
        for ($i = $count; $i--; ) {
            $this->guidProds[] = $guid = $this->guid();
            $unit = $this->idUnits[array_rand($this->idUnits)];
            $group = $this->guidGroups[array_rand($this->guidGroups)];
            $prod = [
                'Ид' => $guid,
                'ПометкаУдаления' => 'false',
                'Наименование' => $this->genRandPhrase(),
                'Описание' => '',
                'Группы' => [
                    'Ид' => $group, //TODO: может относиться к нескольким категориям
                ],
                'БазоваяЕдиница' => [
                    '#' => $unit['id'],
                    '@Код' => $unit['id'],
                    '@НаименованиеПолное' => $unit['name'],
                    '@НаименованиеКраткое' => $unit['short'],
                ],
                'ЗначенияСвойств' => '', //TODO:
                'СтавкиНалогов' => '', //TODO:
                'Вес' => round(rand() / getrandmax() * 1800 + 50) / 100,
                'ЗначенияРеквизитов' => [
                    'ЗначениеРеквизита' => [
                        [
                            'Наименование' => 'ВидНоменклатуры',
                            'Значение' => 'Товар',
                        ],
                        [
                            'Наименование' => 'ТипНоменклатуры',
                            'Значение' => 'Товар',
                        ],
                    ]
                ],
            ];
            $prod = array_merge($prod, $this->getRndImages());
            $prods[] = $prod;
        }
        return ['Каталог' => [
            '@СодержитТолькоИзменения' => 'true',
            'Ид' => $this->guidCatalog,
            'ИдКлассификатора' => $this->guidClassifier,
            'Наименование' => 'Каталог товаров',
            'Описание' => 'Каталог товаров',
            'Товары' => ['Товар' => $prods],
        ]];
    }
    
    /**
     * Генерация import.xml
     * @throws Exception
     */
    private function genClassifier () {
        $cml = CommerceMLElement::getInstance();
        $cls = $cml->addFromArray([
            'Классификатор' => [
                '@СодержитТолькоИзменения' => 'true',
                'Ид' => $this->guidClassifier,
                'Наименование' => 'Каталог товаров',
            ]
        ]);
        $cls->addFromArray($this->genGroups($this->config['categories-count'], $this->config['categories-level']));
        $cls->addFromArray($this->genUnits($this->config['units-count']));
        $cls->addFromArray($this->genCatalog($this->config['products-count']));
        $cml->asEncodingXML('windows-1251', $this->config['export-path'] . 'import.xml');
    }
    
    /**
     * Генерация offers.xml
     * @throws Exception
     */
    private function genOffers () {
        $cml = CommerceMLElement::getInstance();
        $pack = $cml->addFromArray([
            'ПакетПредложений' => [
                '@СодержитТолькоИзменения' => 'true',
                'Ид' => $this->guid(),
                'Наименование' => 'Торговые предложения',
                'ИдКаталога' => $this->guidCatalog,
                'ИдКлассификатора' => $this->guidClassifier,
            ]
        ]);
        $offers = [];
        foreach ($this->guidProds as $guid) {
            $price = 0.1 + rand() / getrandmax() * 100;
            $price *= pow(10, rand(1, 2) * 2);
            $price = round($price, 2);
            $offers[] = [
                'Ид' => $guid,
                'Цены' => [
                    'Цена' => [
                        [
                            'Представление' => str_replace('.', ',', $price) . ' руб. за ед.',
                            'ИдТипаЦены' => 'BASE',
                            'ЦенаЗаЕдиницу' => $price,
                            'Валюта' => 'руб',
                        ],
                    ],
                ],
            ];
        }
        $pack->addFromArray(['Предложения' => ['Предложение' => $offers]]);
        $cml->asEncodingXML('windows-1251', $this->config['export-path'] . 'offers.xml');
    }
    
    /**
     * Генерация rests.xml
     * @throws Exception
     */
    private function genRests () {
        $cml = CommerceMLElement::getInstance();
        $pack = $cml->addFromArray([
            'ПакетПредложений' => [
                '@СодержитТолькоИзменения' => 'true',
                'Ид' => $this->guid(),
                'Наименование' => 'Складские остатки',
                'ИдКаталога' => $this->guidCatalog,
                'ИдКлассификатора' => $this->guidClassifier,
            ]
        ]);
        $offers = [];
        foreach ($this->guidProds as $guid) {
            $price = 0.1 + rand() / getrandmax() * 100;
            $price *= pow(10, rand(1, 2) * 2);
            $price = round($price, 2);
            $offers[] = [
                'Ид' => $guid,
                'Остатки' => [
                    'Остаток' => rand(1, 80)
                ],
            ];
        }
        $pack->addFromArray(['Предложения' => ['Предложение' => $offers]]);
        $cml->asEncodingXML('windows-1251', $this->config['export-path'] . 'rests.xml');
    }
    
    /**
     * Получение экземпляра singleton
     * @param array $config
     * @return CML2Generator
     * @throws Exception
     */
    public static function getInstance (array $config = []) {
        if (!self::$instance) self::$instance = new self($config);
        return self::$instance;
    }
    
    /**
     * Генерация всех файлов. Возвращает информацию
     * @return array
     * @throws Exception
     */
    public function generateFiles () {
        $this->genClassifier();
        $this->genOffers();
        $this->genRests();
        return [
            'categories' => count($this->guidGroups),
            'memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' Mb',
        ];
    }


    /**
     * Получить пакет картинок-заглушек
     * @param string $packURL
     * @throws Exception
     */
    public function downloadImages (string $packURL) {
        $diskUrl = 'https://cloud-api.yandex.net/v1/disk/public/resources/download?public_key=' . urlencode($packURL);
        $apiData = @json_decode(@file_get_contents($diskUrl), true);
        if (!is_array($apiData) || !isset($apiData['href'])) throw new Exception('Ya.disk download error');
        echo 'Download images pack... ';
        $zipFile = $this->config['images-path'] . md5($apiData['href']) . '.zip';
        copy($apiData['href'], $zipFile);
        echo "[OK]\n";
        echo "UNZIP images:\n";
        $num = 0;
        $zip = zip_open($zipFile);
        if (!$zip) throw new Exception('Error in ZIP-file');
        while ($entry = zip_read($zip)) {
            $entrySize = zip_entry_filesize($entry);
            $entryName = zip_entry_name($entry);
            if (!$entrySize) continue;
            if (zip_entry_open($zip, $entry, 'r')) {
                file_put_contents($this->config['images-path'] . $entryName, zip_entry_read($entry, $entrySize));
                zip_entry_close($entry);
                echo (++$num) . '. ' . $entryName . '[' . round($entrySize / 1024, 2) . " Kb]\n";
            }
            else throw new Exception('Error unpack "' . $entryName . '" from ZIP-file');
        }
        zip_close($zip);
        echo 'Delete zip-file... ';
        echo unlink($zipFile) ? '[OK]' : '[Error]';
        echo "\n";
    }
    
}

/**
 * Class CML2Uploader
 * Процессинг обмена
 */
class CML2Uploader {
    /**
     * CURL handler
     * @var resource
     */
    private $curl;
    
    /**
     * URL сервиса принимающего CML2
     * @var string
     */
    private $servUrl;
    
    /**
     * Имя пользователя сервиса принимающего CML2
     * @var string
     */
    private $servUser;
    
    /**
     * Пароль сервиса принимающего CML2
     * @var string
     */
    private $servPass;
    
    /**
     * Поддерживает ли сервис сжатие zip
     * @var bool
     */
    private $servZip;
    
    /**
     * Ограничение размера передаваемых файлов сервису
     * @var bool
     */
    private $servSize;

    /**
     * Время старта импорта
     * @var int
     */
    private $timeStart;
    
    /**
     * Не копировать шаблонные файлы
     * @var bool
     */
    private $nocopy;
    
    /**
     * Отправка запроса на сервер
     * @param string $method
     * @param string $filename
     * @param string $data
     * @return array
     */
    private function exchangeRequest (string $method, string $filename = '', string $data = '') {
        $part = explode(':', $filename);
        $filename = $part[0];
        $part = isset($part[1]) ? ':part' . $part[1] : '';
        echo gmdate('i:s ', time() - $this->timeStart) . $method .
             ($filename ? ' (' . $filename . $part . ')' : '') . ":\n";
        list($type, $mode) = explode('.', $method);
        $params = ['type' => $type, 'mode' => $mode];
        if ($filename) $params['filename'] = $filename;
        $url = $this->servUrl . (strpos($this->servUrl, '?') ? '&' : '?') . http_build_query($params);
        if (!$this->curl) {
            $cookiejar = tempnam('', '');
            $this->curl = curl_init();
            curl_setopt_array($this->curl, [
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_USERAGENT => 'CommerceML2 emulator (TODO: github)',
                CURLOPT_COOKIEJAR => $cookiejar,
                CURLOPT_COOKIEFILE => $cookiejar,
                CURLOPT_POST => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $this->servUser . ':' . $this->servPass,
                CURLOPT_HTTPHEADER => ['Expect:'],
            ]);
        }
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $data,
        ]);
        $log = $result = array_map('trim', explode("\n", trim(curl_exec($this->curl))));
        $status = strtolower($result[0]);
        if (preg_match('#^(progress|success|fail)$#', $status)) {
            echo '  [' . $status . "]\n";
            array_shift($log);
        }
        if (count($log)) echo '  ' . implode("\n  ", $log) . "\n";
        switch ($method) {
            case 'catalog.checkauth':
                if (count($result) > 2) curl_setopt($this->curl, CURLOPT_COOKIE, $result[1] . '=' . $result[2]);
                break;
            case 'catalog.init':
                foreach ($result as $param) {
                    list($key, $val) = explode('=', strtolower($param));
                    if ($key == 'zip') $this->servZip = preg_match('#^(1|on|y|yes|true)$#', $val);
                    elseif ($key == 'file_limit') $this->servSize = (int)$val;
                }
                break;
        }
        return $result;
    }
    
    /**
     * Подготовка файлов к загрузке (создание zip)
     * @param string $file
     * @throws Exception
     */
    private function prepareFiles (string $file) {
        if (!$this->nocopy) copy('templates/' . $file . '.xml', 'temp/' . $file . '.xml');
        if ($file == 'import') {
            $dir = opendir('temp/');
            while ($fname = readdir($dir)) if (preg_match('#^\d+\.jpg$#i', $fname)) unlink('temp/' . $fname);
            closedir($dir);
            preg_match_all('%>(\d+\.jpg)<%i', file_get_contents('temp/import.xml'), $m);
            $images = array_unique($m[1]);
            foreach ($images as $image) copy('templates/images/' . $image, 'temp/' . $image);
        }
        if (!$this->servZip) return;
        $zip = new ZipArchive();
        if (!$zip->open('temp/' . $file . '.zip', ZipArchive::CREATE)) throw new Exception('Can\'t create zip');
        $zip->addFile('temp/' . $file . '.xml', $file . '.xml');
        if ($file == 'import') {
            foreach ($images as $image) $zip->addFile('temp/' . $image, $image);
        }
        $zip->close();
    }
    
    /**
     * Экспорт указанного файла
     * @param string $file
     * @throws Exception
     */
    public function exchangeCatalogFile (string $file) {
        $this->timeStart = time();
        $this->exchangeRequest('catalog.checkauth');
        $this->exchangeRequest('catalog.init');
        $this->prepareFiles($file);
        if ($file == 'import' && !$this->servZip) {
            $dir = opendir('temp/');
            while ($fname = readdir($dir)) if (preg_match('#^\d+\.jpg$#i', $fname)) {
                $ofile = fopen('temp/' . $fname, 'rb');
                $part = 0;
                while (!feof($ofile)) {
                    $part++;
                    $this->exchangeRequest('catalog.file', $fname . ':' . $part, fread($ofile, $this->servSize));
                }
                fclose($ofile);
            }
            closedir($dir);
        }
        $nfile = $file . ($this->servZip ? '.zip' : '.xml');
        $ofile = fopen('temp/' . $nfile, 'rb');
        $part = 0;
        while (!feof($ofile)) {
            $part++;
            $this->exchangeRequest('catalog.file', $nfile . ':' . $part, fread($ofile, $this->servSize));
        }
        fclose($ofile);
        do $res = $this->exchangeRequest('catalog.import', $file . '.xml');
        while (isset($res[0]) && $res[0] == 'progress');
    }
    
    /**
     * Полная эмуляция экспорта (выгрузка/загрузка всех файлов)
     * @throws Exception
     */
    public function exchangeFull () {
        $this->exchangeCatalogFile('import');
        $this->exchangeCatalogFile('offers');
        $this->exchangeCatalogFile('rests');
        $this->exchangeRequest('catalog.complete');
        //TODO: импорт заказов, изменение статуса и экспорт заказов
    }
    
    /**
     * NoCopy setter
     * @param bool $v
     */
    public function setNoCopy (bool $v) {
        $this->nocopy = $v;
    }
    
    /**
     * CML2Uploader constructor.
     * @param string $url
     * @param string $user
     * @param string $pass
     */
    public function __construct (string $url, string $user, string $pass) {
        $this->servUrl = $url;
        $this->servUser = $user;
        $this->servPass = $pass;
        $this->timeStart = time();
        $this->nocopy = false;
    }
    
}

/**
 * Trait RunActions
 * Функционал обработки actions одним классом
 */
trait RunActions {
    
    /**
     * Экземпляр класса
     * @var object
     */
    private static $instance;
    
    /**
     * Основной статический метод запуска действия
     * @param string $action
     * @param array $params
     * @throws Exception
     */
    public static function actionRun (string $action = '', array $params = []) {
        if (!self::$instance) self::$instance = new self();
        if (!$action) $action = $_REQUEST['a'] ?? $GLOBALS['argv'][1] ?? '';
        $action = preg_replace('#[^a-z._:-]#', '', strtolower((string)$action));
        $method = str_replace(['-', '_', ':'], '.', $action);
        if (!$method) throw new Exception('Empty action not supported');
        $method = 'action' . preg_replace_callback('#(?:^|\.)(.)#', function($m){
                return strtoupper($m[1]);
            }, $method);
        if (!method_exists(self::$instance, $method)) throw new Exception('Action [' . $action . '] not supported');
        if (!count($params)) {
            if (count($GLOBALS['argv']) > 2) {
                foreach (array_slice($GLOBALS['argv'], 2) as $param) {
                    if ($pos = strpos($param, '=')) {
                        $params[mb_substr($param, 0, $pos, 'utf8')] = mb_substr($param, $pos + 1, null, 'utf8');
                    }
                    else $params[$param] = true;
                }
            }
            elseif (count($_REQUEST) > 1) $params = $_REQUEST;
        }
        self::$instance->$method($params);
    }
    
    /**
     * Приватный конструктор для синглтона
     */
    private function __construct () {}
    
}


/**
 * Class CML2Emulator
 * Основной класс эмулятора запускаемый из коммандной строки
 */
class CML2Emulator {
    use RunActions;
    
    /**
     * Параметры сервиса принимающего CML2
     * @var string
     */
    private $serv;
    
    /**
     * CML2Emulator constructor.
     * Читает сохраненный конфиг
     */
    private function __construct () {
        $this->serv = is_readable('temp/conf.dat') ?
                      unserialize(file_get_contents('temp/conf.dat')) :
                      array_fill_keys(['url', 'user', 'pass'], '');
    }
    
    /**
     * Установка параметров сервера для отправки файлов
     * @param array $params
     */
    private function actionServ (array $params = []) {
        $params = array_merge($this->serv, $params);
        foreach ($this->serv as $key => &$val) $val = $params[$key];
        unset($val);
        file_put_contents('temp/conf.dat', serialize($this->serv));
        echo $this->serv['user'] . ':' .$this->serv['pass'] . '@' .
             preg_replace('#^https?://#i', '', $this->serv['url']) . "\n";
    }
    
    /**
     * Генерация новых обменных файлов со случайным содержимым
     * @param array $params
     * @throws Exception
     */
    private function actionGenerate (array $params = []) {
        $params = array_merge([
            'categories-count' => 50,
            'categories-level' => 3,
            'products-count' => 450,
            'units-count' => 20,
        ], $params);
        $res = CML2Generator::getInstance($params)->generateFiles();
        foreach ($res as $key => $val) echo $key . ': ' . $val . "\n";
    }
    
    /**
     * Выгрузка данных на сервер
     * @param array $params
     * @throws Exception
     */
    private function actionExchange (array $params = []) {
        $params = array_merge($this->serv, [
            'type' => 'full',
            'nocopy' => 0,
        ], $params);
        $uploader = new CML2Uploader($params['url'], $params['user'], $params['pass']);
        $uploader->setNoCopy($params['nocopy']);
        if ($params['type'] == 'full') $uploader->exchangeFull();
        else $uploader->exchangeCatalogFile($params['type']);
    }
    
    /**
     * Загрузка случайных изображений для товаров
     * @param array $params
     * @throws Exception
     */
    private function actionImages (array $params = []) {
        $params = array_merge($this->serv, [
            'url' => 'https://yadi.sk/d/hQxqmBZY3SRRRA',
        ], $params);
        CML2Generator::getInstance($params)->downloadImages($params['url']);
    }
    
}

CML2Emulator::actionRun();