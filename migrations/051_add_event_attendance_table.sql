-- 051_add_event_attendance_table.sql
-- The churn/wellness analytics engine references event_attendance (guarded by
-- try/catch, but the table should exist). Create it so engagement scoring and
-- any future attendance features work against a real table.

CREATE TABLE IF NOT EXISTS event_attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    member_id     INT NOT NULL,
    event_id      INT NULL,
    event_date    DATE NULL,
    status        VARCHAR(20) DEFAULT 'attended',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_member (member_id),
    KEY idx_event (event_id),
    KEY idx_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
