<?php

class TextileHook {
    public static function beforeSaleCreate($saleData) {
        // Example: Apply textile-specific discount rules
        if(isset($saleData['discount']) && $saleData['discount'] > 50) {
            $saleData['discount'] = 50; // max discount 50
        }
        $saleData['total_amount'] -= $saleData['discount'] ?? 0;
        return $saleData;
    }

    public static function afterSaleCreate($saleResult) {
        return $saleResult;
    }
}
