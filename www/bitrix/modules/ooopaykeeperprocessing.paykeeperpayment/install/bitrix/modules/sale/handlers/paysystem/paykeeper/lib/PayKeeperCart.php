<?php

use \Bitrix\Main\Loader;
use \Bitrix\Sale;
use \Bitrix\Sale\BasketItem;

class PayKeeperCart
{
    /**
     * @var \PaykeeperPayment
     */
    private static $pkObj;
    private $vat;
    private $vatPriority;
    private $convertFullTaxRate;
    private $basketItemType;
    private $basketPaymentType;
    private $basketMeasure;
    private $truCodeName;
    private $deliveryNameFixed;
    private $vatDelivery;
    private $vatDeliveryPriority;
    private $deliveryItemType;
    private $deliveryPaymentType;
    private $discount;
    private $lastIndex;

    public function __construct($pkObj)
    {
        self::$pkObj = $pkObj;
    }

    public function setVat($vat)
    {
        $this->vat = $vat;
        return $this;
    }

    public function setVatPriority($priority)
    {
        $this->vatPriority = $priority;
        return $this;
    }

    public function setConvertFullTaxRate($convert)
    {
        $this->convertFullTaxRate = $convert;
        return $this;
    }

    public function setBasketItemType($type)
    {
        $this->basketItemType = $type;
        return $this;
    }

    public function setBasketPaymentType($type)
    {
        $this->basketPaymentType = $type;
        return $this;
    }

    public function setBasketMeasure($measure)
    {
        $this->basketMeasure = $measure;
        return $this;
    }

    public function setTruCodeName($name)
    {
        $this->truCodeName = $name;
        return $this;
    }

    public function setDeliveryNameFixed($name)
    {
        $this->deliveryNameFixed = $name;
        return $this;
    }

    public function setVatDelivery($vat)
    {
        $this->vatDelivery = $vat;
        return $this;
    }

    /**
     * @param boolean $priority
     * @return $this
     */
    public function setVatDeliveryPriority($priority)
    {
        $this->vatDeliveryPriority = $priority;
        return $this;
    }

    public function setDeliveryItemType($type)
    {
        $this->deliveryItemType = $type;
        return $this;
    }

    public function setDeliveryPaymentType($type)
    {
        $this->deliveryPaymentType = $type;
        return $this;
    }

    public function setDiscount($discount)
    {
        $this->discount = $discount;
        return $this;
    }

    /**
     * @param Sale\Order $order
     * @return array|array[]|\false[][]|\string[][]
     */
    public function processOrderCart($order)
    {
        $this->processBasket($order->getBasket());
        $this->processDelivery($order);
        self::$pkObj->setDiscounts($this->discount || (floatval($order->getSumPaid()) > 0));
        self::$pkObj->correctPrecision();

        return $this->encodeCartToUtf8(self::$pkObj->getFiscalCart());
    }

    /**
     * @param Sale\Basket $basket
     * @param $returnAmount
     * @param $params
     * @return array|array[]|\false[][]|\string[][]
     */
    public static function generateReturnCart($basket, $returnAmount, $params = [])
    {
        self::$pkObj->setOrderTotal($returnAmount);

        $defaultParams = [
            'convert_full_tax_rate' => false,
            'basket_item_type' => 'commodity',
            'basket_payment_type' => 'full_payment',
            'basket_measure' => 'piece',
            'vat' => null,
            'vat_priority' => false,
        ];

        $params = array_merge($defaultParams, $params);

        $cartProcessor = new self(
            self::$pkObj
        );

        $cartProcessor->processBasket($basket);
        self::$pkObj->setDiscounts(true);

        return $cartProcessor->encodeCartToUtf8(self::$pkObj->getFiscalCart());
    }

    /**
     * @param Sale\Basket $basket
     * @return void
     */
    private function processBasket($basket)
    {
        $this->lastIndex = 0;
        foreach ($basket as $item) {
            $name = $item->getField('NAME');
            $quantity = $item->getField('QUANTITY');
            $price = (float)$item->getField('PRICE');

            if ($quantity == 1 && self::$pkObj->single_item_index < 0) {
                self::$pkObj->single_item_index = $this->lastIndex;
            }
            if ($quantity > 1 && self::$pkObj->more_then_one_item_index < 0) {
                self::$pkObj->more_then_one_item_index = $this->lastIndex;
            }

            $vat = $this->getItemVat($item);

            self::$pkObj->updateFiscalCart(
                self::$pkObj->getPaymentFormType(),
                $name,
                $price,
                $quantity,
                0,
                $vat
            );

            self::$pkObj->fiscal_cart[$this->lastIndex]['item_type'] = $this->basketItemType;
            self::$pkObj->fiscal_cart[$this->lastIndex]['payment_type'] = $this->basketPaymentType;
            self::$pkObj->fiscal_cart[$this->lastIndex]['measure'] = $this->basketMeasure;

            $this->processTruCode($item);

            $this->lastIndex++;
        }
    }

    /**
     * @param Sale\BasketItem $item
     * @return mixed|string|null
     */
    private function getItemVat($item)
    {
        $vatRate = $item->getField('VAT_RATE');

        if ($this->vatPriority && $this->vat) {
            return $this->vat;
        }

        if (!is_null($vatRate)) {
            return Utils::getVat($vatRate * 100, false, $this->convertFullTaxRate);
        }

        return $this->vat ?: null;
    }

    /**
     * @param BasketItem $item
     * @return void
     */
    private function processTruCode($item)
    {
        if (empty($this->truCodeName)) {
            return;
        }

        $productId = $item->getField('PRODUCT_ID');
        $this->processProductTruCode($productId);
        $this->processBasketPropertyTruCode($item);
    }

    private function processProductTruCode($productId)
    {
        $productInfo = \CCatalogSKU::GetProductInfo($productId) ?: \CIBlockElement::GetByID($productId)->GetNext();

        if (is_array($productInfo)) {
            $productProperty = \CIBlockElement::GetProperty(
                $productInfo['IBLOCK_ID'],
                $productInfo['ID'],
                [],
                ['CODE' => $this->truCodeName]
            );

            if (($arProperty = $productProperty->GetNext()) && $arProperty['VALUE']) {
                self::$pkObj->fiscal_cart[$this->lastIndex]['tru_code'] = $arProperty['VALUE'];
            }
        }
    }

    /**
     * @param BasketItem $item
     * @return void
     */
    private function processBasketPropertyTruCode($item)
    {
        $basketPropertyCollection = $item->getPropertyCollection();
        foreach ($basketPropertyCollection as $propertyItem) {
            if ($propertyItem->getField('CODE') == $this->truCodeName && $propertyItem->getField('VALUE')) {
                self::$pkObj->fiscal_cart[$this->lastIndex]['tru_code'] = $propertyItem->getField('VALUE');
                break;
            }
        }
    }

    /**
     * @param Sale\Order $order
     * @return void
     */
    private function processDelivery($order)
    {
        $deliveryPrice = $order->getDeliveryPrice();
        if ($deliveryPrice <= 0) {
            return;
        }

        $deliveryId = $order->getField('DELIVERY_ID');
        $dbSaleDelivery = Sale\Delivery\Services\Manager::getById($deliveryId);
        $deliveryName = $this->deliveryNameFixed ?: $dbSaleDelivery['NAME'];

        self::$pkObj->setShippingPrice($deliveryPrice);

        if (!self::$pkObj->checkDeliveryIncluded(self::$pkObj->getShippingPrice(), $deliveryName)) {
            $this->addDeliveryToCart($dbSaleDelivery, $deliveryName, $deliveryPrice);
        }
    }

    private function addDeliveryToCart(array $dbSaleDelivery, $deliveryName, $deliveryPrice)
    {
        $vatDelivery = $this->getDeliveryVat($dbSaleDelivery);

        self::$pkObj->setUseDelivery();
        self::$pkObj->updateFiscalCart(
            self::$pkObj->getPaymentFormType(),
            $deliveryName,
            $deliveryPrice,
            1,
            0,
            $vatDelivery
        );

        self::$pkObj->delivery_index = $this->lastIndex;
        self::$pkObj->fiscal_cart[$this->lastIndex]['item_type'] = $this->deliveryItemType;
        self::$pkObj->fiscal_cart[$this->lastIndex]['payment_type'] = $this->deliveryPaymentType;
    }

    private function getDeliveryVat(array $dbSaleDelivery)
    {
        if ($this->vatDeliveryPriority && $this->vatDelivery) {
            return $this->vatDelivery;
        }

        if (!empty($dbSaleDelivery['VAT_ID'])) {
            $vatDelivery = \CCatalogVat::GetByID($dbSaleDelivery['VAT_ID'])->Fetch();
            if ($vatDelivery && !is_null($vatDelivery['RATE'])) {
                return Utils::getVat($vatDelivery['RATE'], false, $this->convertFullTaxRate);
            }
        }

        return $this->vatDelivery ?: null;
    }

    /**
     * @param array $cart
     * @return array|array[]|\false[][]|\string[][]
     */
    private function encodeCartToUtf8($cart)
    {
        return array_map(function($item) {
            return array_map(function($value) {
                if (!is_string($value)) {
                    return $value;
                }
                $enc = mb_detect_encoding($value, 'ASCII, UTF-8, windows-1251', true);
                return ($enc == 'UTF-8') ? $value : iconv($enc, 'UTF-8', $value);
            }, $item);
        }, $cart);
    }
}