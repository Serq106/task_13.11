<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
use Bitrix\Main\Loader;

CModule::IncludeModule('iblock');
Loader::includeModule('highloadblock');

use Bitrix\Highloadblock as HL;
use Bitrix\Main\Entity;


class SiteUtil extends CIBlockElement {

    protected static $objectsElements = array();
    protected static $lastNewsElement = array();
    protected static $objectsHLCategory = array();
    protected static $categoryHL = 'Category';
    protected static $elementCodeIblockNews = 'NEWS_'.LANGUAGE_ID;
    protected static $elementIdIblockNews = null;
    protected static $addElementCount = 0;
    protected static $searchElementCount = 0;

    protected static $params = Array(
        'max_len' => '100', // обрезает символьный код до 100 символов
        'change_case' => 'L', // буквы преобразуются к нижнему регистру
        'replace_space' => '_', // меняем пробелы на нижнее подчеркивание
        'replace_other' => '_', // меняем левые символы на нижнее подчеркивание
        'delete_repeat_replace' => 'true', // удаляем повторяющиеся нижние подчеркивания
        'use_google' => 'false', // отключаем использование google
    );

    public static function write($data){
        echo '<pre>'. print_r($data, 1) .'</pre>';
    }

    public static function number($n, $titles) {
        $cases = array(2, 0, 1, 1, 1, 2);
        return $titles[($n % 100 > 4 && $n % 100 < 20) ? 2 : $cases[min($n % 10, 5)]];
    }

    public static function GetIBlockIDByCode($code){
        $arrFilter = array(
            'ACTIVE'  => 'Y',
            'CODE'    => $code,
        );

        $res = CIBlock::GetList(Array("SORT" => "ASC"), $arrFilter, false);
        $arIBlockId = "";

        if ($ar_res = $res->Fetch()) {
            $arIBlockId = $ar_res["ID"];
        }

        return $arIBlockId;
    }


    /*
     *
     * BEGIN функции для работы с Highload
     *
     * */

    /*Один раз получаем все категории для новостей*/
    public static function getHL(){
        $entity = HL\HighloadBlockTable::compileEntity(self::$categoryHL);
        $entity_data_class = $entity->getDataClass();

        $rsData = $entity_data_class::getList(array(
            'select' => array(
                'UF_NAME',
                'UF_XML_ID'
            ),
            'order' => array(
                'ID' => 'ASC'
            ),
        ));
        while($arData = $rsData->Fetch()){
            self::$objectsHLCategory[$arData['UF_XML_ID']] = $arData['UF_NAME'];
        }
    }

    //Функция добавления новой категории в Highload.
    public static function addHL($nameCat, $code){

        $entity = HL\HighloadBlockTable::compileEntity(self::$categoryHL);
        $entity_data_class = $entity->getDataClass();

        $data = array(
            'UF_NAME' => $nameCat,
            'UF_XML_ID'=> $code,
        );

        if($CATEGORY_ID = $entity_data_class::add($data)) {
            echo 'Добавлена новая категория: '.$nameCat .'<br>';
            self::$objectsHLCategory[$code] = $nameCat;
        } else {
            echo 'Ошибка при создание новости: '.$CATEGORY_ID->LAST_ERROR.'<br>';
        }

    }

    /*
    *
    * END функции для работы с Highload
    *
    * */

    /*
    *
    * BEGIN функции для работы с инфоблокам Новости
    *
    * */
    public static function load_from_xml($xmlData) {

        self::$elementIdIblockNews = self::GetIBlockIDByCode(self::$elementCodeIblockNews);

        $arrData = (json_decode(json_encode($xmlData), true));

        foreach ($arrData['channel']['item'] as $item=>$arItem){

            $data_active_from = date('d.m.Y G:i:s', strtotime($arItem['pubDate']));
            $code_element = Cutil::translit($arItem['title'],'ru', self::$params);

            //Проверяем элемент с RSS ленты с последним елементом в инфоблоке. Делаем это для того, что бы уменьшить кол-во запросов к БД
            if(!(self::$lastNewsElement['DATE_ACTIVE_FROM'] != $data_active_from && self::$lastNewsElement['NAME'] != trim($arItem['title']))){
                break;
            }

            $xml_id = Cutil::translit($arItem['category'],'ru', self::$params);

            $props['CATEGORY'] = $xml_id;
            $props['URL'] = $arItem['link'];

            //Проверяем есть ли категория в Highload-блоке, если нет, то добавляем новую категорию
            if(empty(self::$objectsHLCategory[$xml_id])){
                self::addHL($arItem['category'], $xml_id);
            }

            self::$objectsElements[$item]['IBLOCK_ID'] = self::$elementIdIblockNews;
            self::$objectsElements[$item]['NAME'] = trim($arItem['title']);
            self::$objectsElements[$item]['CODE'] =  $code_element;
            self::$objectsElements[$item]['ACTIVE'] =  'Y';
            self::$objectsElements[$item]['PREVIEW_TEXT'] =  $arItem['description'];
            self::$objectsElements[$item]['DATE_ACTIVE_FROM'] =  $data_active_from;
            self::$objectsElements[$item]['PROPERTY_VALUES'] =  $props;

            self::addElement(self::$objectsElements[$item]);

        }

        if(self::$addElementCount > 0){
            echo 'Количество новых новостей: '.self::$addElementCount.'<br>';
        } else {
            echo 'Нет новых элементов <br>';
        }

    }

    //Функция добавления новой новости
    public static function addElement($element){
        $el = new CIBlockElement;
        if($PRODUCT_ID = $el->Add($element)) {
            echo 'Добавлена новая новость ID: '.$PRODUCT_ID .'<br>';
            self::$addElementCount++;
        } else {
            echo 'Ошибка при создание новости: '.$el->LAST_ERROR.'<br>';
        }
    }

    //Данная функция получает последнюю новость, данная функция надо для того, чтобы ограничить добавления новостей с RSS ленты.
    public static function lastElement(){
        $arSelect = array('NAME', 'DATE_ACTIVE_FROM');
        $arFilter = array('IBLOCK_ID' => self::$elementIdIblockNews);
        $res = CIBlockElement::GetList(array('DATE_ACTIVE_FROM'=>'DESC'), $arFilter, false, array('nPageSize' => 1), $arSelect);
        if ($ob = $res->fetch()) {
            self::$lastNewsElement['NAME'] = $ob['NAME'];
            self::$lastNewsElement['DATE_ACTIVE_FROM'] = $ob['DATE_ACTIVE_FROM'];
        }

    }

    public static function searchElement($q){
        $searchElement = '';
        foreach (self::$objectsHLCategory as $code=>$arCatagory){
            if(mb_strpos(mb_strtolower($arCatagory), mb_strtolower($q)) !== false ){
                $arFilterCode[] = $code;
                $searchElement .= $arCatagory .', ';
                self::$searchElementCount++;
            }
        }

        if(self::$searchElementCount > 0){
            $textElement =  self::number(self::$searchElementCount, array('элемент', 'элемента', 'элементов'));
            echo 'По поисковой фразе: "'.$q.'" было найдено '. self::$searchElementCount . ' '.$textElement.'.<br>';
            echo 'Найденные категории: '.substr($searchElement, 0, -2).'<br>';
        } else {
            echo 'По поисковой фразе: "'.$q.'" не найдено ни одного элемента.<br>';
        }

        $arSelect = array('ID', 'NAME', 'PREVIEW_TEXT', 'DATE_ACTIVE_FROM', 'PROPERTY_CATEGORY', 'PROPERTY_URL');
        $arFilter = array('IBLOCK_ID' => self::$elementIdIblockNews, 'PROPERTY_CATEGORY'=>$arFilterCode);
        $res = CIBlockElement::GetList(array('DATE_ACTIVE_FROM'=>'DESC'), $arFilter, false, false, $arSelect);
        while($ob = $res->fetch()) {
            $arsearchElement[$ob['ID']]['NAME'] = $ob['NAME'];
            $arsearchElement[$ob['ID']]['URL'] = $ob['PROPERTY_URL_VALUE'];
            $arsearchElement[$ob['ID']]['PREVIEW_TEXT'] = $ob['PREVIEW_TEXT'];
            $arsearchElement[$ob['ID']]['DATE_ACTIVE_FROM'] = $ob['DATE_ACTIVE_FROM'];
            $arsearchElement[$ob['ID']]['CATEGORY'] = self::$objectsHLCategory[$ob['PROPERTY_CATEGORY_VALUE']];
        }

        self::write($arsearchElement);
    }
    /*
    *
    * END функции для работы с инфоблокам Новости
    *
    * */



}
?>

<h1>Тестовое задание для разработчиков Bitrix</h1>

<p>Вызов функции парсинга новостей из RSS <a href="/?parser_rss=y">Запустить парсер</a></p>

<br><br>

<form action="/">
    <input type="text" name="q" value="<?=$_GET['q']?>" placeholder="Поиск по категории" autocomplete="off">
    <button type="submit">Поиск</button>
</form>




<?

$SiteUtil = new \SiteUtil;
$SiteUtil->getHL();
$SiteUtil->lastElement();

if($_GET['parser_rss'] == 'y'){

    $xmlData=simplexml_load_file('https://lenta.ru/rss', null, LIBXML_NOCDATA);
    $SiteUtil->load_from_xml($xmlData);
}
if(!empty($_GET['q'])){
    $SiteUtil->searchElement($_GET['q']);
}

?>