<?php
require_once __DIR__.'/../models/Sale.php';
require_once __DIR__.'/../models/SaleItem.php';
require_once __DIR__.'/../models/NumberingScheme.php';

class BillingService {
    private $saleModel;
    private $saleItemModel;
    private $numberingModel;

    public function __construct($pdo) {
        $this->saleModel      = new Sale($pdo);
        $this->saleItemModel  = new SaleItem($pdo);
        $this->numberingModel = new NumberingScheme($pdo);
    }

    public function createSale($org_id, $data) {
        $pdo = $this->saleModel->pdo;
        $pdo->beginTransaction();

        try {
            // --- Validate products belong to this outlet + org ---
            $productIds = array_map(fn($i) => (int)$i['product_id'], $data['items']);
            if (count($productIds) === 0) {
                throw new Exception("At least one item is required");
            }

            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $params = array_merge([$org_id, $data['outlet_id']], $productIds);
            $stmt = $pdo->prepare("SELECT id FROM products WHERE org_id=? AND outlet_id=? AND id IN ($placeholders)");
            $stmt->execute($params);
            $existingProducts = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $missingProducts = array_diff($productIds, $existingProducts);
            if(count($missingProducts) > 0){
                throw new Exception("Products not found in this outlet: ".implode(',', $missingProducts));
            }

            // --- Generate invoice number ---
            $invoice_no = $this->numberingModel->getNextInvoiceNumber($org_id);

            // --- GST calculation ---
            $cgst = $sgst = $igst = 0;
            if (!empty($data['gst_type']) && !empty($data['gst_rate'])) {
                if ($data['gst_type'] === 'CGST_SGST') {
                    $cgst = $sgst = $data['gst_rate'] / 2;
                } else {
                    $igst = $data['gst_rate'];
                }
            }

            // --- Create sale ---
            $sale_id = $this->saleModel->create([
                'org_id'       => $org_id,
                'outlet_id'    => $data['outlet_id'],
                'total_amount' => $data['total_amount'],
                'discount'     => $data['discount'] ?? 0,
                'cgst'         => $cgst,
                'sgst'         => $sgst,
                'igst'         => $igst
            ]);

            // --- Insert items ---
            foreach ($data['items'] as $item) {
                $quantity = (int)($item['quantity'] ?? 0);
                $rate     = (float)($item['rate'] ?? $item['price'] ?? 0);
                $amount   = (float)($item['amount'] ?? ($quantity * $rate));

                if ($quantity <= 0) {
                    throw new Exception("Invalid quantity for product_id {$item['product_id']}");
                }
                if ($rate <= 0) {
                    throw new Exception("Invalid rate/price for product_id {$item['product_id']}");
                }

                $this->saleItemModel->create([
                    'sale_id'    => $sale_id,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity'   => $quantity,
                    'rate'       => $rate,
                    'amount'     => $amount
                ]);
            }

            $pdo->commit();
            return [
                'sale_id'    => $sale_id,
                'invoice_no' => $invoice_no,
                'cgst'       => $cgst,
                'sgst'       => $sgst,
                'igst'       => $igst
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function listSales($org_id) {
        return $this->saleModel->list($org_id);
    }
}
