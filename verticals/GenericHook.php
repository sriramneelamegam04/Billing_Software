<?php

class GenericHook {
    public static function beforeSaleCreate($saleData) {
        // No changes for generic vertical
        return $saleData;
    }

    public static function afterSaleCreate($saleResult) {
        return $saleResult;
    }
}
