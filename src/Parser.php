<?php

namespace src;

require '../vendor/autoload.php';

require_once('Product.php');

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Parser {
    private $ini_array;

    private $baseURL;

    private $categoryElement;
    private $endingSubCategoryElement;

    private $productLinkElement;

    private $skuElement;

    private $productNameElement;

    private $productDescriptionElement;
    private $reserveProductDescriptionElement;
    private $descriptionAsHtml;

    private $priceElement;
    private $discountedPriceElement;
    
    public function __construct() {
        $this->ini_array = parse_ini_file("../appsettings.ini", true);

        $this->baseURL = $this->ini_array['ParserSettings']['BaseURL'] ?? null;

        $this->categoryElement = $this->ini_array['ParserSettings']['CategoryElement'] ?? null;
        $this->endingSubCategoryElement = $this->ini_array['ParserSettings']['EndingSubCategoryElement'] ?? null;

        $this->productLinkElement = $this->ini_array['ParserSettings']['ProductLinkElement'] ?? null;

        $this->skuElement = $this->ini_array['ParserSettings']['SkuElement'] ?? null;

        $this->productNameElement = $this->ini_array['ParserSettings']['ProductNameElement'] ?? null;

        $this->productDescriptionElement = $this->ini_array['ParserSettings']['ProductDescriptionElement'] ?? null;
        $this->reserveProductDescriptionElement = $this->ini_array['ParserSettings']['ReserveProductDescriptionElement'] ?? null;
        $this->descriptionAsHtml = $this->ini_array['ParserSettings']['DescriptionAsHtml'] ?? null;

        $this->priceElement = $this->ini_array['ParserSettings']['PriceElement'] ?? null;
        $this->discountedPriceElement = $this->ini_array['ParserSettings']['DiscountedPriceElement'] ?? null;
        
        var_dump($this->ini_array);

        if (!$this->baseURL) {
            echo 'Configure the "ParserSettings:BaseURL" field in "appsettings.ini"';
            exit;
        }
    }
    
    public function parse(array $pages) {
        $client = HttpClient::create();
        
        foreach($pages as $page) {
            $response = $client->request('GET', $this->baseURL . "?page=$page");

            if ($response->getStatusCode() !== 200) {
                throw new Exception('Failed to retrieve the webpage. Status code: ' . $response->getStatusCode());
            }

            $content = $response->getContent();

            $crawler = new Crawler($content);
        
            $productsLinks = $crawler->filter($this->productLinkElement)->extract(['href']);

            $products = $this->parseIndividualProducts($productsLinks, $client);
            
            $this->exportToExcel($products, '../exports', 'exported');
        }
    }
    
    private function parseIndividualProducts($productsLinks, $client) {
        $products = [];

        foreach($productsLinks as $productLink) {
            $response = $client->request('GET', $productLink);

            if ($response->getStatusCode() !== 200) {
                throw new Exception('Failed to retrieve the webpage. Status code: ' . $response->getStatusCode());
            }

            $content = $response->getContent();
            $crawler = new Crawler($content);
            
            $product = new Product();

            $product->sku = $this->removeChars($crawler->filter($this->skuElement)->first()->extract(['_text'])[0]);
            
            $product->name = $crawler->filter($this->productNameElement)->first()->extract(['_text'])[0];

            $productDescription = $crawler->filter($this->productDescriptionElement)->first()->extract(['_text'])[0];
            if($productDescription != null && gettype($productDescription) == "string") {
                $product->description = $productDescription;
            }
            else {
                $product->description = '';
            }
            
            $product->price = $this->removeChars($crawler->filter($this->priceElement)->first()->extract(['_text'])[0]);
            
            $productDiscountedPrice = $crawler->filter($this->discountedPriceElement)->first()->extract(['_text'])[0];
            if($productDiscountedPrice != null && gettype($productDiscountedPrice) == "string") {
                $product->discountedPrice = $this->removeChars($productDiscountedPrice);
            }
            else {
                $product->discountedPrice = '';
            }
            
            $product->breadCrumbs = $this->parseBreadCrumbs($crawler);
            
            $product->link = $productLink;
            
            array_push($products, $product);
            
            var_dump($product);
        }
         
        echo "Products: \n---\n";
        var_dump($products);
        echo "---";
        
        return $products;
    }
    
    private function parseBreadCrumbs($crawler) {    
        $breadCrumbsArray = $crawler->filter($this->categoryElement)->extract(['_text']);
        
        if($this->endingSubCategoryElement != '' && $this->endingSubCategoryElement != null) {
            array_push($breadCrumbsArray, $crawler->filter($this->endingSubCategoryElement)->extract(['_text'])[0]);
        }
        
        $breadCrumbs = implode('|', $breadCrumbsArray);
        
        return $breadCrumbs;
    }
    
    private function exportToExcel(array $data, string $filePath, string $fileName) {
        if (empty($data)) {
            throw new Exception('The data array is empty.');
        }
        
        $date = date('d-m-Y-H-i-s', time());
        $fileName = $fileName . '-' . $date;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $firstItem = $data[0];
        $properties = array_keys(get_object_vars($firstItem));

        foreach ($properties as $col => $property) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $property);
        }

        foreach ($data as $row => $obj) {
            foreach ($properties as $col => $property) {
                $sheet->setCellValueByColumnAndRow($col + 1, $row + 2, $obj->$property);
            }
        }

        if (!file_exists($filePath)) {
            mkdir($filePath, 0777, true);
        }

        $writer = new Xlsx($spreadsheet);
        $fileFullPath = rtrim($filePath, '/') . '/' . $fileName . '.xlsx';
        $writer->save($fileFullPath);
    }
    
    private function removeChars(string $str): string {
        $newStr = preg_replace('/[^0-9.]/', '', $str);
        return $newStr;
    }
}

try {
    date_default_timezone_set('Europe/Kyiv');
    
    $parser = new Parser();   
    $parser->parse(range(1,1));
} 
catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
