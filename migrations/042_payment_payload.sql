-- 042 支付订单增加 payload（记录套餐 / 商品与数量等业务参数）

ALTER TABLE payment_orders
    ADD COLUMN payload VARCHAR(255) NOT NULL DEFAULT '' AFTER ref_id;

-- @@DOWN

ALTER TABLE payment_orders DROP COLUMN payload;
