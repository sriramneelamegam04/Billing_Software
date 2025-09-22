<?php
function feature_enabled_for_vertical($vertical, $key_name){
  $pdo = require __DIR__ . '/../bootstrap/db.php';
  if (!$pdo) return false;
  $sql = "SELECT 1 FROM vertical_features vf 
          JOIN features f ON vf.feature_id=f.id 
          WHERE LOWER(vf.vertical)=LOWER(?) 
            AND f.key_name=? 
            AND vf.enabled=1 
          LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$vertical, $key_name]);
  return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}
