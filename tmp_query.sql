SELECT t.id, t.terminal_id, t.shift_id, TIME(t.created_at) as time, t.total, t.status,
       GROUP_CONCAT(CONCAT(p.name, ' (', c.name, ')') SEPARATOR ', ') as items
FROM pos_transactions t
LEFT JOIN pos_transaction_items ti ON ti.transaction_id = t.id
LEFT JOIN products p ON p.id = ti.product_id
LEFT JOIN categories c ON c.id = p.category_id
WHERE DATE(t.created_at) = '2026-05-05'
  AND TIME(t.created_at) < '10:00:00'
  AND t.status = 'completed'
GROUP BY t.id
ORDER BY t.created_at;
