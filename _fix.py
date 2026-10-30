p='c:/xampp/htdocs/business/leads.php'
s=open(p,encoding='utf-8').read()
old="""$lead_preview = [
                        'lead_number' => (string)$l['lead_number'],
                        'customer_name' => $customer_name,
                        'phone' => $contact_phone ?: 'Phone not recorded',
                        'location' => (string)($l['location'] ?: '—'),
                        'product' => $product_name,
                        'status' => (string)($statuses[$l['status']] ?? $l['status']),
                        'orders' => $can_orders ? (int)$l['order_count'] : 0,
                        'next_follow_up' => $l['next_follow_up_at'] ? date('M d, Y', strtotime($l['next_follow_up_at'])) : '—',
                        'created' => date('M d, Y', strtotime($l['created_at'])),
                        'notes' => trim((string)($l['notes'] ?? '')),
                    ];"""
new="""$lead_preview = [
                        'lead_id' => (int)$l['lead_id'],
                        'lead_number' => (string)$l['lead_number'],
                        'customer_name' => $customer_name,
                        'phone' => $contact_phone ?: 'Phone not recorded',
                        'location' => (string)($l['location'] ?: '—'),
                        'product' => $product_name,
                        'status' => (string)($statuses[$l['status']] ?? $l['status']),
                        'status_key' => (string)$l['status'],
                        'orders' => $can_orders ? (int)$l['order_count'] : 0,
                        'next_follow_up' => $l['next_follow_up_at'] ? date('M d, Y', strtotime($l['next_follow_up_at'])) : '—',
                        'followup_raw' => $l['next_follow_up_at'] ? (string)$l['next_follow_up_at'] : '',
                        'created' => date('M d, Y', strtotime($l['created_at'])),
                        'notes' => trim((string)($l['notes'] ?? '')),
                    ];"""
assert old in s
s=s.replace(old,new)
open(p,'w',encoding='utf-8').write(s)
print('done')
