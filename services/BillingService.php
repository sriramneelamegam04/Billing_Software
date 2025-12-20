<?php
require_once __DIR__.'/../models/Sale.php';
require_once __DIR__.'/../models/SaleItem.php';
require_once __DIR__.'/../models/NumberingScheme.php';

class BillingService {

    private $saleModel;
    private $saleItemModel;
    private $numberingModel;
    public  $pdo;

    public function __construct($pdo) {
        $this->pdo             = $pdo;
        $this->saleModel       = new Sale($pdo);
        $this->saleItemModel   = new SaleItem($pdo);
        $this->numberingModel  = new NumberingScheme($pdo);
    }

    /* ==========================================================
       CREATE SALE
       (PALAYA LOGIC SAME – GST STORAGE EXTENDED)
    ========================================================== */
    public function createSale($org_id, $data) {
        $pdo = $this->pdo;

        try {

            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("At least one item is required");
            }

            if (empty($data['customer_id'])) {
                throw new Exception("customer_id is required");
            }

            /* ---------------- VALIDATE PRODUCTS ---------------- */
            $productIds = array_unique(array_map(fn($i) => (int)$i['product_id'], $data['items']));
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));

            $stmt = $pdo->prepare("
                SELECT id FROM products
                WHERE org_id=? AND outlet_id=? AND id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$org_id, $data['outlet_id']], $productIds));

            if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($productIds)) {
                throw new Exception("Invalid product(s) detected");
            }

/* ---------------- CREATE SALE HEADER ---------------- */

     $sale_id = $this->saleModel->create([
    'org_id'          => $org_id,
    'outlet_id'       => $data['outlet_id'],
    'customer_id'     => $data['customer_id'] ?? null,
    'status'          => $data['status'] ?? 0,

    // 🔥 SUMMARY VALUES (already calculated in sales/create.php)
    'taxable_amount'  => $data['taxable_amount'] ?? 0,
    'cgst'            => $data['cgst'] ?? 0,
    'sgst'            => $data['sgst'] ?? 0,
    'igst'            => $data['igst'] ?? 0,
    'round_off'       => $data['round_off'] ?? 0,
    'total_amount'    => $data['total_amount'],

    'discount'        => $data['discount'] ?? 0,
    'note'            => $data['note'] ?? null
]);

/* ---------------- INSERT SALE ITEMS ---------------- */
foreach ($data['items'] as $item) {

    $qty  = (float)$item['quantity'];
    $rate = (float)$item['rate'];

    if ($qty <= 0 || $rate <= 0) {
        throw new Exception("Invalid qty/rate for product_id {$item['product_id']}");
    }

    // 🔥 FINAL LINE TOTAL (DB MATCH)
    $lineAmount =
        $item['amount']
        ?? (($item['taxable_amount'] ?? 0)
            + ($item['cgst'] ?? 0)
            + ($item['sgst'] ?? 0)
            + ($item['igst'] ?? 0));

    $this->saleItemModel->create([
        'sale_id'        => $sale_id,
        'product_id'     => (int)$item['product_id'],
        'variant_id'     => $item['variant_id'] ?? null,
        'quantity'       => $qty,
        'rate'           => $rate,

        // 🔥 ITEM-WISE GST (TABLE MATCHED)
        'taxable_amount' => $item['taxable_amount'] ?? 0,
        'gst_rate'       => $item['gst_rate'] ?? 0,
        'cgst'           => $item['cgst'] ?? 0,
        'sgst'           => $item['sgst'] ?? 0,
        'igst'           => $item['igst'] ?? 0,

        // 🔥 FINAL AMOUNT COLUMN
        'amount'         => round($lineAmount, 2),

        'meta'           => $item['meta'] ?? null
    ]);
}

return [
    'sale_id'    => $sale_id,
    'invoice_no' => $this->numberingModel->getNextInvoiceNumber($org_id)
];

   } catch (Exception $e) {
        throw $e;
    }
}
    /* ==========================================================
       DELETE SALE (UNCHANGED)
    ========================================================== */
    public function deleteSale($org_id, $sale_id)
    {
        $pdo = $this->pdo;

        $stmt = $pdo->prepare("
            SELECT id, outlet_id 
            FROM sales 
            WHERE id=? AND org_id=? LIMIT 1
        ");
        $stmt->execute([$sale_id, $org_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception("Sale not found");
        }

        $outlet_id = (int)$sale['outlet_id'];

        $stmt = $pdo->prepare("
            SELECT product_id, variant_id, quantity, rate, total_amount
            FROM sale_items
            WHERE sale_id=?
        ");
        $stmt->execute([$sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM loyalty_points WHERE sale_id=? AND org_id=?");
        $stmt->execute([$sale_id, $org_id]);

        $stmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id=?");
        $stmt->execute([$sale_id]);

        $stmt = $pdo->prepare("DELETE FROM sales WHERE id=? AND org_id=?");
        $stmt->execute([$sale_id, $org_id]);

        return [
            'sale_id'   => $sale_id,
            'outlet_id' => $outlet_id,
            'items'     => $items
        ];
    }

    /* ==========================================================
       UPDATE SALE (PALAYA FLOW SAME + GST FIELDS)
    ========================================================== */
    public function updateSale($org_id, $sale_id, $oldSale, $data)
    {
        $pdo = $this->pdo;

        if (!isset($data['items'])) {
            $skip = ['items'];
            $updates = [];
            $params  = [];

            foreach ($data as $k => $v) {
                if (in_array($k, $skip, true)) continue;
                $updates[] = "$k=?";
                $params[]  = $v;
            }

            if ($updates) {
                $params[] = $sale_id;
                $params[] = $org_id;
                $stmt = $pdo->prepare(
                    "UPDATE sales SET ".implode(',', $updates)." WHERE id=? AND org_id=?"
                );
                $stmt->execute($params);
            }
            return true;
        }

        // Delete old items
        $stmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id=?");
        $stmt->execute([$sale_id]);

        // Insert new items
        foreach ($data['items'] as $item) {
            $this->saleItemModel->create([
                'sale_id'        => $sale_id,
                'product_id'     => (int)$item['product_id'],
                'variant_id'     => $item['variant_id'] ?? null,
                'quantity'       => $item['quantity'],
                'rate'           => $item['rate'],
                'taxable_amount' => $item['taxable_amount'],
                'gst_rate'       => $item['gst_rate'],
                'cgst'           => $item['cgst'],
                'sgst'           => $item['sgst'],
                'igst'           => $item['igst'],
                'gst_amount'     => $item['gst_amount'],
                'total_amount'   => $item['total_amount'],
                'meta'           => $item['meta'] ?? null
            ]);
        }

        return true;
    }

    public function listSales($org_id) {
        return $this->saleModel->list($org_id);
    }
}
