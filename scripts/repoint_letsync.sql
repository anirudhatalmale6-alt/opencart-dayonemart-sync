UPDATE `ocdh_setting` SET `value` = '["https://green7.app/webhooks/product/handle"]'  WHERE `code`='module_letsync' AND `key`='module_letsync_webhooks_product';
UPDATE `ocdh_setting` SET `value` = '["https://green7.app/webhooks/category/handle"]' WHERE `code`='module_letsync' AND `key`='module_letsync_webhooks_category';
UPDATE `ocdh_setting` SET `value` = '["https://green7.app/webhooks/customer/handle"]' WHERE `code`='module_letsync' AND `key`='module_letsync_webhooks_customer';
UPDATE `ocdh_setting` SET `value` = '["https://green7.app/webhooks/order/handle"]'    WHERE `code`='module_letsync' AND `key`='module_letsync_webhooks_order';
UPDATE `ocdh_setting` SET `value` = '1' WHERE `code`='module_letsync' AND `key`='module_letsync_status';
