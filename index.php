<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
use Bitrix\Main\Loader;

CModule::IncludeModule('iblock');
Loader::includeModule('highloadblock');

use Bitrix\Highloadblock as HL;
use Bitrix\Main\Entity;


class SiteUtil{

    private $objectsElements = array();
    private $lastNewsElement = array();
    private $objectsHLCategory = array();
    private $categoryHL = 'Category';
    private $elementCodeIblockNews = 'NEWS_'.LANGUAGE_ID;
    private $elementIdIblockNews = null;
    private $addElementCount = 0;
    private $searchElementCount = 0;

    CONST PARAMS = [
        'max_len' => '100', // обрезает символьный код до 100 символов
        'change_case' => 'L', // буквы преобразуются к нижнему регистру
        'replace_space' => '_', // меняем пробелы на нижнее подчеркивание
        'replace_other' => '_', // меняем левые символы на нижнее подчеркивание
        'delete_repeat_replace' => 'true', // удаляем повторяющиеся нижние подчеркивания
        'use_google' => 'false', // отключаем использование google
    ];

    private function write($data){
        echo '<pre>'. print_r($data, 1) .'</pre>';
    }

    private function number($n, $titles) {
        $cases = array(2, 0, 1, 1, 1, 2);
        return $titles[($n % 100 > 4 && $n % 100 < 20) ? 2 : $cases[min($n % 10, 5)]];
    }

    private  function GetIBlockIDByCode($code){
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
    public function getHL(){
        $entity = HL\HighloadBlockTable::compileEntity($this->categoryHL);
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
            $this->objectsHLCategory[$arData['UF_XML_ID']] = $arData['UF_NAME'];
        }
    }

    //Функция добавления новой категории в Highload.
    private function addHL($nameCat, $code){

        $entity = HL\HighloadBlockTable::compileEntity($this->categoryHL);
        $entity_data_class = $entity->getDataClass();

        $data = array(
            'UF_NAME' => $nameCat,
            'UF_XML_ID'=> $code,
        );

        if($CATEGORY_ID = $entity_data_class::add($data)) {
            echo 'Добавлена новая категория: '.$nameCat .'<br>';
            $this->objectsHLCategory[$code] = $nameCat;
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
    public function load_from_xml($xmlData) {

        $this->elementIdIblockNews = self::GetIBlockIDByCode($this->elementCodeIblockNews);

        $arrData = (json_decode(json_encode($xmlData), true));

        foreach ($arrData['channel']['item'] as $item=>$arItem){

            $data_active_from = date('d.m.Y G:i:s', strtotime($arItem['pubDate']));
            $code_element = Cutil::translit($arItem['title'],'ru', self::PARAMS);

            //Проверяем элемент с RSS ленты с последним елементом в инфоблоке. Делаем это для того, что бы уменьшить кол-во запросов к БД
            if(!($this->lastNewsElement['DATE_ACTIVE_FROM'] != $data_active_from && $this->lastNewsElement['NAME'] != trim($arItem['title']))){
                break;
            }

            $xml_id = Cutil::translit($arItem['category'],'ru', self::PARAMS);

            $props['CATEGORY'] = $xml_id;
            $props['URL'] = $arItem['link'];

            //Проверяем есть ли категория в Highload-блоке, если нет, то добавляем новую категорию
            if(empty($this->objectsHLCategory[$xml_id])){
                self::addHL($arItem['category'], $xml_id);
            }

            $this->objectsElements[$item]['IBLOCK_ID'] = $this->elementIdIblockNews;
            $this->objectsElements[$item]['NAME'] = trim($arItem['title']);
            $this->objectsElements[$item]['CODE'] =  $code_element;
            $this->objectsElements[$item]['ACTIVE'] =  'Y';
            $this->objectsElements[$item]['PREVIEW_TEXT'] =  $arItem['description'];
            $this->objectsElements[$item]['DATE_ACTIVE_FROM'] =  $data_active_from;
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
    private function addElement($element){
        $el = new CIBlockElement;
        if($PRODUCT_ID = $el->Add($element)) {
            echo 'Добавлена новая новость ID: '.$PRODUCT_ID .'<br>';
            $this->addElementCount++;
        } else {
            echo 'Ошибка при создание новости: '.$el->LAST_ERROR.'<br>';
        }
    }

    //Данная функция получает последнюю новость, данная функция надо для того, чтобы ограничить добавления новостей с RSS ленты.
    public function lastElement(){
        $arSelect = array('NAME', 'DATE_ACTIVE_FROM');
        $arFilter = array('IBLOCK_ID' => $this->elementIdIblockNews);
        $res = CIBlockElement::GetList(array('DATE_ACTIVE_FROM'=>'DESC'), $arFilter, false, array('nPageSize' => 1), $arSelect);
        if ($ob = $res->fetch()) {
            $this->lastNewsElement['NAME'] = $ob['NAME'];
            $this->lastNewsElement['DATE_ACTIVE_FROM'] = $ob['DATE_ACTIVE_FROM'];
        }
    }

    public function searchElement($q){
        $searchElement = '';
        foreach ($this->objectsHLCategory as $code=>$arCatagory){
            if(mb_strpos(mb_strtolower($arCatagory), mb_strtolower($q)) !== false ){
                $arFilterCode[] = $code;
                $searchElement .= $arCatagory .', ';
                $this->searchElementCount++;
            }
        }

        if($this->searchElementCount > 0){
            $textElement =  self::number($this->searchElementCount, array('элемент', 'элемента', 'элементов'));
            echo 'По поисковой фразе: "'.$q.'" было найдено '. $this->searchElementCount . ' '.$textElement.'.<br>';
            echo 'Найденные категории: '.substr($searchElement, 0, -2).'<br>';
        } else {
            echo 'По поисковой фразе: "'.$q.'" не найдено ни одного элемента.<br>';
        }

        $arSelect = array('ID', 'NAME', 'PREVIEW_TEXT', 'DATE_ACTIVE_FROM', 'PROPERTY_CATEGORY', 'PROPERTY_URL');
        $arFilter = array('IBLOCK_ID' => $this->elementIdIblockNews, 'PROPERTY_CATEGORY'=>$arFilterCode);
        $res = CIBlockElement::GetList(array('DATE_ACTIVE_FROM'=>'DESC'), $arFilter, false, false, $arSelect);
        while($ob = $res->fetch()) {
            $arsearchElement[$ob['ID']]['NAME'] = $ob['NAME'];
            $arsearchElement[$ob['ID']]['URL'] = $ob['PROPERTY_URL_VALUE'];
            $arsearchElement[$ob['ID']]['PREVIEW_TEXT'] = $ob['PREVIEW_TEXT'];
            $arsearchElement[$ob['ID']]['DATE_ACTIVE_FROM'] = $ob['DATE_ACTIVE_FROM'];
            $arsearchElement[$ob['ID']]['CATEGORY'] = $this->objectsHLCategory[$ob['PROPERTY_CATEGORY_VALUE']];
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
    <input type="text" name="q" value="<?=$_GET['q']?>" placeholder="Поиск по категории" required autocomplete="off">
    <button type="submit">Поиск</button>
</form>




<?

$SiteUtil = new \SiteUtil;

if($_GET['parser_rss'] == 'y'){
    $SiteUtil->getHL();
    $SiteUtil->lastElement();
    $xmlData=simplexml_load_file('https://lenta.ru/rss', null, LIBXML_NOCDATA);
    $SiteUtil->load_from_xml($xmlData);
}
if(!empty($_GET['q'])){
    $SiteUtil->getHL();
    $SiteUtil->lastElement();
    $SiteUtil->searchElement($_GET['q']);
}

?>