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
       - Assumes caller (sales/create.php) already performed
         stock checks. This method inserts sale + sale_items.
       - Inventory adjustments are handled by DB triggers on
         sale_items (AFTER INSERT).
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
            'org_id'       => $org_id,
            'outlet_id'    => $data['outlet_id'],
            'customer_id'  => $data['customer_id'] ?? null, // 🔥 MAIN FIX
            'status'       => $data['status'] ?? 0,
            'total_amount' => $data['total_amount'],
            'discount'     => $data['discount'] ?? 0,

            // GST AMOUNTS ONLY (already calculated in sales/create.php)
            'cgst'         => $data['cgst'] ?? 0,
            'sgst'         => $data['sgst'] ?? 0,
            'igst'         => $data['igst'] ?? 0,

            'note'         => $data['note'] ?? null
        ]);

        /* ---------------- INSERT SALE ITEMS ---------------- */
        foreach ($data['items'] as $item) {

            $qty  = (float)$item['quantity'];
            $rate = (float)$item['rate'];
            $amt  = (float)$item['amount'];

            if ($qty <= 0 || $rate <= 0) {
                throw new Exception("Invalid qty/rate for product_id {$item['product_id']}");
            }

            $this->saleItemModel->create([
                'sale_id'    => $sale_id,
                'product_id' => (int)$item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity'   => $qty,
                'rate'       => $rate,
                'amount'     => $amt,
                'meta'       => $item['meta'] ?? null
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
       DELETE SALE
       - Uses DB triggers: deletion of sale_items will trigger
         inventory restoration (AFTER DELETE trigger).
       - Does NOT manually adjust inventory to avoid double-changes.
    ========================================================== */
    public function deleteSale($org_id, $sale_id)
    {
        $pdo = $this->pdo;

        // Verify sale exists and belongs to org
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

        // Fetch items BEFORE deletion (so we can return them)
        $stmt = $pdo->prepare("
            SELECT product_id, variant_id, quantity, rate, amount
            FROM sale_items
            WHERE sale_id=?
        ");
        $stmt->execute([$sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Delete loyalty records for this sale (if any)
        $stmt = $pdo->prepare("DELETE FROM loyalty_points WHERE sale_id=? AND org_id=?");
        $stmt->execute([$sale_id, $org_id]);

        // Delete sale items (AFTER DELETE trigger will restore inventory)
        $stmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id=?");
        $stmt->execute([$sale_id]);

        // Delete sale header
        $stmt = $pdo->prepare("DELETE FROM sales WHERE id=? AND org_id=?");
        $stmt->execute([$sale_id, $org_id]);

        return [
            'sale_id'   => $sale_id,
            'outlet_id' => $outlet_id,
            'items'     => $items
        ];
    }


    /* ==========================================================
       UPDATE SALE (supports replacing items)
       - Restores stock via deleting old sale_items (DB trigger),
         then inserts new items (DB trigger will deduct).
       - Does not do manual inventory arithmetic.
    ========================================================== */
    public function updateSale($org_id, $sale_id, $oldSale, $data)
    {
        $pdo = $this->pdo;

        // Determine outlet (either incoming or old)
        $outlet_id = (int)($data['outlet_id'] ?? $oldSale['outlet_id']);

        // If items not provided -> only update header fields (no inventory work)
        if (!isset($data['items'])) {
            $skip = ['gst_type', 'gst_rate', 'gstin'];

            $updates = [];
            $params  = [];

            foreach ($data as $k => $v) {
                if ($k === 'items') continue;
                if (in_array($k, $skip, true)) continue;

                $updates[] = "$k=?";
                $params[]  = $v;
            }

            if (count($updates) > 0) {
                $params[] = $sale_id;
                $params[] = $org_id;

                $stmt = $pdo->prepare("UPDATE sales SET " . implode(',', $updates) . " WHERE id=? AND org_id=?");
                $stmt->execute($params);
            }

            return true;
        }

        // -------------------------
        // Items provided -> replace flow
        // -------------------------

        // Fetch old items (we may want to return them or log them)
        $stmt = $pdo->prepare("SELECT id, product_id, variant_id, quantity, rate, amount FROM sale_items WHERE sale_id=?");
        $stmt->execute([$sale_id]);
        $oldItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Delete old items (AFTER DELETE trigger will restore inventory)
        $stmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id=?");
        $stmt->execute([$sale_id]);

        // Insert new items (AFTER INSERT trigger will deduct inventory)
        foreach ($data['items'] as $item) {
            $pid  = (int)$item['product_id'];
            $qty  = (float)($item['quantity'] ?? 0);
            $rate = (float)($item['rate'] ?? $item['price'] ?? 0);
            $amt  = isset($item['amount']) ? (float)$item['amount'] : ($qty * $rate);

            if ($qty <= 0 || $rate <= 0) {
                throw new Exception("Invalid qty or rate for product $pid");
            }

            $this->saleItemModel->create([
                'sale_id'    => $sale_id,
                'product_id' => $pid,
                'variant_id' => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
                'quantity'   => $qty,
                'rate'       => $rate,
                'amount'     => $amt,
                'meta'       => $item['meta'] ?? null
            ]);
        }

        // Update header fields (excluding GST meta)
        $skip = ['items', 'gst_type', 'gst_rate', 'gstin'];
        $updates = [];
        $params  = [];

        foreach ($data as $k => $v) {
            if (in_array($k, $skip, true)) continue;

            $updates[] = "$k=?";
            $params[]  = $v;
        }

        if (count($updates) > 0) {
            $params[] = $sale_id;
            $params[] = $org_id;

            $stmt = $pdo->prepare("UPDATE sales SET " . implode(',', $updates) . " WHERE id=? AND org_id=?");
            $stmt->execute($params);
        }

        return true;
    }


    public function listSales($org_id) {
        return $this->saleModel->list($org_id);
    }
}
