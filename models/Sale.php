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
       - DB schema aligned
       - Supports SALE & RETURN
       - Item-level discount snapshot
       - GST safe
       - Dashboard ready
    ===================================================== */
    public function create(array $data): int
    {
        /* -----------------------------------------
           META SAFE BUILD
        ----------------------------------------- */
        $meta = [];

        if (!empty($data['meta']) && is_array($data['meta'])) {
            $meta = $data['meta'];
        }

        /* ---------- ITEM SNAPSHOT (AUDIT) ---------- */
        if (!empty($data['items']) && is_array($data['items'])) {

            $meta['items_summary'] = [];

            foreach ($data['items'] as $item) {
                $meta['items_summary'][] = [
                    'product_id'      => $item['product_id'],
                    'variant_id'      => $item['variant_id'] ?? null,
                    'qty'             => $item['quantity'],
                    'original_rate'   => $item['original_rate'] ?? $item['rate'],
                    'final_rate'      => $item['rate'],
                    'discount'        => $item['discount'] ?? null,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'taxable_amount'  => $item['taxable_amount'] ?? 0,
                    'gst_rate'        => $item['gst_rate'] ?? 0,
                    'cgst'            => $item['cgst'] ?? 0,
                    'sgst'            => $item['sgst'] ?? 0,
                    'igst'            => $item['igst'] ?? 0,
                    'line_total'      => $item['amount'] ?? 0
                ];
            }
        }

        $meta_json = !empty($meta)
            ? json_encode($meta, JSON_UNESCAPED_UNICODE)
            : null;

        /* -----------------------------------------
           GST TOTAL (IMPORTANT)
        ----------------------------------------- */
        $cgst = round($data['cgst'] ?? 0, 2);
        $sgst = round($data['sgst'] ?? 0, 2);
        $igst = round($data['igst'] ?? 0, 2);

        $gst_total = round($cgst + $sgst + $igst, 2);

        /* -----------------------------------------
           INSERT SALE (SCHEMA MATCHED)
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
                gst_total,
                round_off,

                note,
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
                :gst_total,
                :round_off,

                :note,
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

            // status: 0 = sale, -1 = return (your convention)
            ':status'        => $data['status'] ?? 0,

            ':taxable_amount'=> round($data['taxable_amount'] ?? 0, 2),
            ':total_amount'  => round($data['total_amount'], 2),

            // sale-level discount ONLY
            ':discount'      => round($data['discount'] ?? 0, 2),

            ':cgst'          => $cgst,
            ':sgst'          => $sgst,
            ':igst'          => $igst,
            ':gst_total'     => $gst_total,

            ':round_off'     => round($data['round_off'] ?? 0, 2),

            // optional note (return reason / admin remark)
            ':note'          => $data['note'] ?? null,

            ':meta'          => $meta_json
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
