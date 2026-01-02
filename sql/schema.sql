CREATE TABLE IF NOT EXISTS hosts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    is_testing_bufferbloat BOOLEAN DEFAULT FALSE,
    speedtest_server_id VARCHAR(16) DEFAULT NULL,
    last_speed_test TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mtr_results (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    host_id INT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    target VARCHAR(255) NOT NULL,
    is_under_load BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
);

CREATE INDEX idx_mtr_results_host_timestamp ON mtr_results(host_id, timestamp);

CREATE TABLE IF NOT EXISTS mtr_hops (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    result_id BIGINT NOT NULL,
    hop_number INT NOT NULL,
    hostname VARCHAR(255),
    loss FLOAT,
    sent INT,
    last FLOAT,
    avg FLOAT,
    best FLOAT,
    worst FLOAT,
    stdev FLOAT,
    FOREIGN KEY (result_id) REFERENCES mtr_results(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS speed_tests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    host_id INT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    download_mbps FLOAT,
    upload_mbps FLOAT,
    latency_idle FLOAT,
    latency_download FLOAT,
    latency_upload FLOAT,
    jitter_idle FLOAT,
    jitter_download FLOAT,
    jitter_upload FLOAT,
    server_id INT,
    server_name VARCHAR(255),
    result_url VARCHAR(255),
    FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
);

CREATE INDEX idx_speed_tests_host_timestamp ON speed_tests(host_id, timestamp);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
);

-- Seed defaults
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
('default_metric', 'avg'),
('default_hop', 'last'),
('default_period', '60m'),
('speedtest_interval', '60'),
('speedtest_server_id', ''),
('data_retention_days', '30');

-- Seed data for 2 WAN connections
INSERT IGNORE INTO hosts (name, api_key) VALUES ('WAN 1', 'key_wan1');
INSERT IGNORE INTO hosts (name, api_key) VALUES ('WAN 2', 'key_wan2');
