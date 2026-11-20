<?php
class StockManager {
    private $db; // PDO kelimesini kaldırdık
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Stok hareketi ekler ve �r�n�n g�ncel stok miktar�n� yeniden hesaplar.
     */
    public function addMovement(int $productId, float $quantity, string $type, ?int $referenceId = null, ?string $notes = null): bool {
        try {
            $this->db->beginTransaction();
            
            // 1. Stok hareketini kaydet
            $stmt = $this->db->prepare("
                INSERT INTO stock_movements (product_id, quantity, movement_type, reference_id, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$productId, $quantity, $type, $referenceId, $notes]);
            
            // 2. �r�n�n toplam stok miktar�n� hareketlere g�re yeniden hesapla ve g�ncelle
            $this->updateProductStockQuantity($productId);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }
    
    /**
     * Verilen �r�n�n t�m hareketlerini tarayarak g�ncel sto�u hesaplar ve products tablosuna yazar.
     */
    private function updateProductStockQuantity(int $productId): void {
        // IN ve ADJUSTMENT (art�� y�nl�) eklenir, OUT ve WASTE ��kar�l�r.
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