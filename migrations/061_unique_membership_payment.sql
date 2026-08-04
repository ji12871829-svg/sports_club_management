-- 061_unique_membership_payment.sql
-- Hard idempotency for membership activation.
--
-- activate_membership_for_payment() has a SELECT-then-INSERT guard on
-- payment_id, but two truly concurrent duplicate callbacks could both pass
-- the SELECT and double-insert. A UNIQUE index on payment_id makes the
-- database reject the second insert outright.
--
-- MariaDB/MySQL allow multiple NULLs in a UNIQUE index, so manually created
-- memberships without a linked payment (payment_id = NULL) are unaffected.

-- Drop the existing non-unique index (created by the base schema) and replace
-- it with a unique one. Using ALGORITHM=INPLACE avoids a table rebuild.
ALTER TABLE member_memberships
    DROP INDEX idx_member_memberships_payment,
    ADD UNIQUE INDEX uq_member_memberships_payment (payment_id),
    ALGORITHM = INPLACE;

-- Restore a non-unique index for lookups by member+status+end_date that
-- reference payment_id in joins (kept separate from the unique constraint).
ALTER TABLE member_memberships
    ADD INDEX idx_member_memberships_payment_join (payment_id),
    ALGORITHM = INPLACE;
