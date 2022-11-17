<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Localization\Loc,
    Bitrix\Main\ORM\Data\DataManager,
    Bitrix\Main\ORM\Fields\IntegerField,
    Bitrix\Main\ORM\Fields\TextField,
    Bitrix\Main\Loader,
    Bitrix\Main\Entity,
    Bitrix\Highloadblock as HL;

CModule::IncludeModule('iblock');
Loader::includeModule('highloadblock');

/**
 * Class CategoryTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> UF_NAME text optional
 * <li> UF_SORT int optional
 * <li> UF_XML_ID text optional
 * <li> UF_LINK text optional
 * <li> UF_DESCRIPTION text optional
 * <li> UF_FULL_DESCRIPTION text optional
 * <li> UF_DEF int optional
 * <li> UF_FILE int optional
 * </ul>
 *
 * @package Bitrix\Hlbd
 **/

class CategoryTable extends DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_hlbd_category';
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return [
            new IntegerField(
                'ID',
                [
                    'primary' => true,
                    'autocomplete' => true,
                    'title' => Loc::getMessage('CATEGORY_ENTITY_ID_FIELD')
                ]
            ),
            new TextField(
                'UF_NAME',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_NAME_FIELD')
                ]
            ),
            new IntegerField(
                'UF_SORT',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_SORT_FIELD')
                ]
            ),
            new TextField(
                'UF_XML_ID',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_XML_ID_FIELD')
                ]
            ),
            new TextField(
                'UF_LINK',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_LINK_FIELD')
                ]
            ),
            new TextField(
                'UF_DESCRIPTION',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_DESCRIPTION_FIELD')
                ]
            ),
            new TextField(
                'UF_FULL_DESCRIPTION',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_FULL_DESCRIPTION_FIELD')
                ]
            ),
            new IntegerField(
                'UF_DEF',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_DEF_FIELD')
                ]
            ),
            new IntegerField(
                'UF_FILE',
                [
                    'title' => Loc::getMessage('CATEGORY_ENTITY_UF_FILE_FIELD')
                ]
            ),
        ];
    }
}

class CategoryHLB
{
    private $objectsHLBCategory = [];

    public function getList()
    {
        $arCatagorys = CategoryTable::getList(
            [
                "select" => [
                    'NAME' => 'UF_NAME',
                    'XML_ID' => 'UF_XML_ID',
                ],
                "order" => [
                    'UF_SORT' => 'ASC',
                ],
            ]
        );

        while($arCatagory = $arCatagorys->Fetch()){
            $this->objectsHLBCategory[$arCatagory['XML_ID']] = $arCatagory['NAME'];
        }

    }

    public function add($nameCat, $code)
    {
        $data = [
            'UF_NAME' => $nameCat,
            'UF_XML_ID'=> $code,
        ];

        $this->objectsHLBCategory[$code] = $nameCat;

        CategoryTable::add($data);
    }

    public function getElementCatagory($xml_id)
    {
        return $this->objectsHLBCategory[$xml_id];
    }

    public function allElementCatagory()
    {
        return $this->objectsHLBCategory;
    }
}




class SiteUtil
{
    private $objectsElements = [];
    private $lastNewsElement = [];
    private $elementCodeIblockNews = 'NEWS_'.LANGUAGE_ID;
    private $elementIdIblockNews = null;
    private $addElementCount = 0;
    private $searchElementCount = 0;
    private $CategoryHLB;

    public function __construct()
    {
        $this->CategoryHLB = new CategoryHLB;
    }

    CONST PARAMS = [
        'max_len' => '100', // обрезает символьный код до 100 символов
        'change_case' => 'L', // буквы преобразуются к нижнему регистру
        'replace_space' => '_', // меняем пробелы на нижнее подчеркивание
        'replace_other' => '_', // меняем левые символы на нижнее подчеркивание
        'delete_repeat_replace' => 'true', // удаляем повторяющиеся нижние подчеркивания
        'use_google' => 'false', // отключаем использование google
    ];

    private function writeSearchElement($arSearchElements)
    {
        echo '<pre>'. print_r($arSearchElements, 1) .'</pre>';
    }

    private function getRussianWordNumber($col_max, $word1, $word2, $word3)
    {
        $col_max = abs($col_max) % 100;
        $col_min = $col_max % 10;

        if ($col_max > 10 && $col_max < 20)
            return $word3;

        if ($col_min > 1 && $col_min < 5)
            return $word2;

        if ($col_min == 1)
            return $word1;

        return $word3;
    }

    private function getIBlockIDByCode($code)
    {
        $arIblock = \Bitrix\Iblock\IblockTable::getList(array(
            'select' => ['ID'],
            'filter' => ['CODE' => $code],
        ))->fetch();

        return $arIblock['ID'];
    }

    /*
    *
    * BEGIN функции для работы с инфоблокам Новости
    *
    * */
    public function loadFromXml($fileRssLenta)
    {
        $this->elementIdIblockNews = self::getIBlockIDByCode($this->elementCodeIblockNews);
        $this->CategoryHLB->getList();
        self::lastElement();
        
        $arrDataRss = (json_decode(json_encode($fileRssLenta), true));

        foreach ($arrDataRss['channel']['item'] as $item=>$arItem){
            $dataActiveFrom = date('d.m.Y G:i:s', strtotime($arItem['pubDate']));
            $codeElement = Cutil::translit($arItem['title'],'ru', self::PARAMS);

            //Проверяем элемент с RSS ленты с последним елементом в инфоблоке. Делаем это для того, что бы уменьшить кол-во запросов к БД
            if(!($this->lastNewsElement['DATE_ACTIVE_FROM'] != $dataActiveFrom && $this->lastNewsElement['NAME'] != trim($arItem['title']))){
                break;
            }

            $xmlId = Cutil::translit($arItem['category'],'ru', self::PARAMS);
            $props['CATEGORY'] = $xmlId;
            $props['URL'] = $arItem['link'];

            //Проверяем есть ли категория в Highload-блоке, если нет, то добавляем новую категорию
            if(empty($this->CategoryHLB->getElementCatagory($xmlId))){
                $this->CategoryHLB->add($arItem['category'], $xmlId);
            }

            $this->objectsElements[$item]['IBLOCK_ID'] = $this->elementIdIblockNews;
            $this->objectsElements[$item]['NAME'] = trim($arItem['title']);
            $this->objectsElements[$item]['CODE'] =  $codeElement;
            $this->objectsElements[$item]['ACTIVE'] =  'Y';
            $this->objectsElements[$item]['PREVIEW_TEXT'] =  $arItem['description'];
            $this->objectsElements[$item]['DATE_ACTIVE_FROM'] =  $dataActiveFrom;
            $this->objectsElements[$item]['PROPERTY_VALUES'] =  $props;

            self::addElement($this->objectsElements[$item]);
        }

        if($this->addElementCount > 0){
            echo 'Количество новых новостей: '.$this->addElementCount.'<br>';
        } else {
            echo 'Нет новых элементов <br>';
        }

    }

    //Функция добавления новой новости
    private function addElement($element)
    {
        $el = new CIBlockElement;
        if($newElemeniId = $el->Add($element)) {
            echo 'Добавлена новая новость ID: '.$newElemeniId .'<br>';
            $this->addElementCount++;
        } else {
            echo 'Ошибка при создание новости: '.$el->LAST_ERROR.'<br>';
        }
    }

    //Данная функция получает последнюю новость, данная функция надо для того, чтобы ограничить добавления новостей с RSS ленты.
    public function lastElement()
    {
        $lastElements = \Bitrix\Iblock\Elements\ElementNewsTable::getList([
            'order'  => ['ACTIVE_FROM' => 'DESC'],
            'select' => ['NAME', 'ACTIVE_FROM'],
            'filter' => ['=ACTIVE' => 'Y'],
            'cache'  => ['ttl' => 3600],
            'limit'  => 1,
        ])->fetch();

        $this->lastNewsElement['NAME'] = $lastElements['NAME'];
        $this->lastNewsElement['DATE_ACTIVE_FROM'] = CDatabase::FormatDate($lastElements['ACTIVE_FROM']);
    }

    public function searchElement($q)
    {
        $this->CategoryHLB->getList();
        $allCategory = $this->CategoryHLB->allElementCatagory();

        $searchElement = '';

        foreach ($allCategory as $code=>$arCatagory){
            if(mb_strpos(mb_strtolower($arCatagory), mb_strtolower($q)) !== false ){
                $arFilterCode[] = $code;
                $searchElement .= $arCatagory .', ';
                $this->searchElementCount++;
            }
        }

        if($this->searchElementCount > 0){
            $textElement =  self::getRussianWordNumber($this->searchElementCount, 'элемент', 'элемента', 'элементов');
            echo 'По поисковой фразе: "'.$q.'" было найдено '. $this->searchElementCount . ' '.$textElement.'.<br>';
            echo 'Найденные категории: '.substr($searchElement, 0, -2).'<br>';
        } else {
            echo 'По поисковой фразе: "'.$q.'" не найдено ни одного элемента.<br>';
        }

        $elements = \Bitrix\Iblock\Elements\ElementNewsTable::getList([
            'select' => ['ID', 'NAME', 'PREVIEW_TEXT', 'ACTIVE_FROM', 'CATEGORY', 'URL'],
            'filter' => ['=ACTIVE' => 'Y', 'IBLOCK_ELEMENTS_ELEMENT_NEWS_CATEGORY_VALUE' => $arFilterCode],
            'cache'  => ['ttl' => 3600],
        ])->fetchAll();

        global $DB;

        foreach ($elements as $element) {
            $arSearchElements[$element['ID']]['NAME'] = $element['NAME'];
            $arSearchElements[$element['ID']]['URL'] = $element['IBLOCK_ELEMENTS_ELEMENT_NEWS_URL_VALUE'];
            $arSearchElements[$element['ID']]['PREVIEW_TEXT'] = $element['PREVIEW_TEXT'];
            $arSearchElements[$element['ID']]['DATE_ACTIVE_FROM'] = CDatabase::FormatDate($element['ACTIVE_FROM']);
            $arSearchElements[$element['ID']]['CATEGORY'] = $this->CategoryHLB->getElementCatagory($element['IBLOCK_ELEMENTS_ELEMENT_NEWS_CATEGORY_VALUE']);
        }

        self::writeSearchElement($arSearchElements);
    }

}
?>



<h1>Тестовое задание для разработчиков Bitrix</h1>

<p>Вызов функции парсинга новостей из RSS <a href="/?parser_rss=y">Запустить парсер</a></p>

<br><br>

<form action="/">
    <input type="text" name="q" value="<?=$_GET['q']?>" placeholder="Поиск по категории" required autocomplete="off">
    <button type="submit">Поиск</button>
</form>




<?

$SiteUtil = new \SiteUtil;

if($_GET['parser_rss'] == 'y'){
    $xmlData=simplexml_load_file('https://lenta.ru/rss', null, LIBXML_NOCDATA);
    $SiteUtil->loadFromXml($xmlData);
}
if(!empty($_GET['q'])){
    $SiteUtil->searchElement($_GET['q']);
}

?>