<?php
// Odoo API configuration placeholders
// Isi sesuai environment Odoo Anda. Jika kosong, sync akan gagal dengan pesan informatif.
define('ODOO_API_BASE_URL', getenv('ODOO_API_BASE_URL') ?: '');
define('ODOO_API_TOKEN', getenv('ODOO_API_TOKEN') ?: ''); // Bearer token atau API key

// Endpoint relatif, misalnya:
// - /api/products?fields=id,default_code,name,uom
// - /api/stock?location_code={code}
define('ODOO_PRODUCTS_ENDPOINT', getenv('ODOO_PRODUCTS_ENDPOINT') ?: '/api/products');
define('ODOO_STOCK_ENDPOINT', getenv('ODOO_STOCK_ENDPOINT') ?: '/api/stock');
define('ODOO_SYNC_SYSTEM_TOKEN', getenv('ODOO_SYNC_SYSTEM_TOKEN') ?: '');
?>
