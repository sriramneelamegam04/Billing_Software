<?php

class PharmacyHook {
    public static function beforeSaleCreate($saleData) {
        // Example: Ensure no restricted items
        foreach($saleData['items'] as $item) {
            if(isset($item['restricted']) && $item['restricted']) {
                throw new Exception("Restricted item cannot be sold.");
            }
        }
        return $saleData;
    }

    public static function afterSaleCreate($saleResult) {
        return $saleResult;
    }
}
