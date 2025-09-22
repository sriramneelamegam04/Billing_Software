<?php
require_once __DIR__.'/../bootstrap/db.php';

class NumberingScheme {
    private $pdo;
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getNextInvoiceNumber($org_id) {
        $stmt = $this->pdo->prepare("SELECT next_invoice_no FROM numbering_schemes WHERE org_id=?");
        $stmt->execute([$org_id]);
        $row = $stmt->fetch();
        $next = $row ? $row['next_invoice_no'] : 1;

        // Update for next time
        if($row) {
            $stmt = $this->pdo->prepare("UPDATE numbering_schemes SET next_invoice_no=? WHERE org_id=?");
            $stmt->execute([$next+1,$org_id]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO numbering_schemes (org_id,next_invoice_no) VALUES (?,?)");
            $stmt->execute([$org_id,2]);
        }
        return $next;
    }
}
