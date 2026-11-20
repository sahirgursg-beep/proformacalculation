<?php
class StockManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Stok hareketi ekler ve ürünün güncel stok miktarını yeniden hesaplar.
     */
    public function addMovement($productId, $quantity, $type, $referenceId = null, $notes = null) {
        try {
            $this->db->beginTransaction();

            // 1. Stok hareketini kaydet
            $stmt = $this->db->prepare("
                INSERT INTO stock_movements (product_id, quantity, movement_type, reference_id, notes) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$productId, $quantity, $type, $referenceId, $notes]);

            // 2. Ürünün toplam stok miktarını hareketlere göre yeniden hesapla ve güncelle
            $this->updateProductStockQuantity($productId);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Verilen ürünün tüm hareketlerini tarayarak güncel stoğu hesaplar ve products tablosuna yazar.
     */
    private function updateProductStockQuantity($productId) {
        // IN ve ADJUSTMENT (artış yönlü) eklenir, OUT ve WASTE çıkarılır.
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN movement_type IN ('IN', 'ADJUSTMENT') THEN quantity ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN movement_type IN ('OUT', 'WASTE') THEN quantity ELSE 0 END), 0) as total_stock
            FROM stock_movements 
            WHERE product_id = ?
        ");
        $stmt->execute([$productId]);
        $result = $stmt->fetch();
        $newStock = $result['total_stock'] ?? 0;

        $updateStmt = $this->db->prepare("UPDATE products SET current_stock_quantity = ? WHERE product_id = ?");
        $updateStmt->execute([$newStock, $productId]);
    }
}
?>