ALTER TABLE pos_shifts
  ADD COLUMN last_heartbeat DATETIME NULL DEFAULT NULL AFTER status,
  ADD COLUMN heartbeat_session VARCHAR(64) NULL DEFAULT NULL AFTER last_heartbeat;
