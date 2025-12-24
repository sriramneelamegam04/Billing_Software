<?php

class Sale
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* =====================================================
       CREATE SALE
       - Backward compatible
       - Supports product/variant discounts
       - GST safe
       - Audit ready
    ===================================================== */
    public function create(array $data): int
    {
        /* -----------------------------------------
           META SAFE BUILD
        ----------------------------------------- */
        $meta = [];

        // existing meta support
        if (!empty($data['meta']) && is_array($data['meta'])) {
            $meta = $data['meta'];
        }

        // 🔥 store item-level discount snapshot
        if (!empty($data['items']) && is_array($data['items'])) {

            $meta['items_summary'] = [];

            foreach ($data['items'] as $item) {
                $meta['items_summary'][] = [
                    'product_id'       => $item['product_id'],
                    'variant_id'       => $item['variant_id'] ?? null,
                    'qty'              => $item['quantity'],
                    'original_rate'    => $item['original_rate'] ?? $item['rate'],
                    'final_rate'       => $item['rate'],
                    'discount'         => $item['discount'] ?? null,
                    'discount_amount'  => $item['discount_amount'] ?? 0,
                    'taxable_amount'   => $item['taxable_amount'] ?? 0,
                    'gst_rate'         => $item['gst_rate'] ?? 0,
                    'cgst'             => $item['cgst'] ?? 0,
                    'sgst'             => $item['sgst'] ?? 0,
                    'igst'             => $item['igst'] ?? 0,
                    'line_total'       => $item['amount'] ?? 0
                ];
            }
        }

        $meta_json = !empty($meta)
            ? json_encode($meta, JSON_UNESCAPED_UNICODE)
            : null;

        /* -----------------------------------------
           INSERT SALE
        ----------------------------------------- */
        $stmt = $this->pdo->prepare("
            INSERT INTO sales (
                org_id,
                outlet_id,
                customer_id,

                status,
                taxable_amount,
                total_amount,

                discount,
                cgst,
                sgst,
                igst,
                round_off,

                meta,
                created_at
            ) VALUES (
                :org_id,
                :outlet_id,
                :customer_id,

                :status,
                :taxable_amount,
                :total_amount,

                :discount,
                :cgst,
                :sgst,
                :igst,
                :round_off,

                :meta,
                NOW()
            )
        ");

        $stmt->execute([

            ':org_id'        => (int)$data['org_id'],
            ':outlet_id'     => (int)$data['outlet_id'],

            // nullable customer
            ':customer_id'   => (!empty($data['customer_id']) && $data['customer_id'] > 0)
                                ? (int)$data['customer_id']
                                : null,

            ':status'          => $data['status'] ?? 0,
            ':taxable_amount'  => round($data['taxable_amount'] ?? 0, 2),
            ':total_amount'    => round($data['total_amount'], 2),

            // 🔥 sale-level discount ONLY
            ':discount'        => round($data['discount'] ?? 0, 2),

            ':cgst'            => round($data['cgst'] ?? 0, 2),
            ':sgst'            => round($data['sgst'] ?? 0, 2),
            ':igst'            => round($data['igst'] ?? 0, 2),
            ':round_off'       => round($data['round_off'] ?? 0, 2),

            ':meta'            => $meta_json
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
