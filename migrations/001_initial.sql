-- Initial schema for YouTube Shorts Factory

CREATE TABLE videos (
    id               INTEGER PRIMARY KEY,
    scheduled_date   TEXT NOT NULL UNIQUE,
    topic            TEXT,
    title            TEXT,
    script           TEXT,
    description      TEXT,
    sources_json     TEXT,
    status           TEXT NOT NULL DEFAULT 'NEW'
        CHECK (status IN ('NEW','SCRIPT_GENERATING','SCRIPT_READY','FACT_CHECKING',
                          'ASSETS_READY','VOICE_READY','VIDEO_RENDERING','VIDEO_READY',
                          'UPLOADING','PUBLISHED','FAILED')),
    audio_path       TEXT,
    video_path       TEXT,
    youtube_video_id TEXT UNIQUE,
    youtube_url      TEXT,
    created_at       TEXT NOT NULL,
    updated_at       TEXT NOT NULL,
    published_at     TEXT,
    last_error       TEXT
);
CREATE INDEX idx_videos_status ON videos(status);

CREATE TABLE tasks (
    id         INTEGER PRIMARY KEY,
    video_id   INTEGER NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
    task_type  TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'PENDING'
        CHECK (status IN ('PENDING','RUNNING','DONE','FAILED')),
    attempts   INTEGER NOT NULL DEFAULT 0,
    next_run_at TEXT,
    locked_at  TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (video_id, task_type)
);
CREATE INDEX idx_tasks_runnable ON tasks(status, next_run_at);

CREATE TABLE assets (
    id           INTEGER PRIMARY KEY,
    video_id     INTEGER NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
    source_url   TEXT,
    local_path   TEXT,
    license_info TEXT,
    asset_type   TEXT,
    content_hash TEXT,
    created_at   TEXT NOT NULL
);
CREATE INDEX idx_assets_video ON assets(video_id);
CREATE INDEX idx_assets_hash  ON assets(content_hash);

CREATE TABLE logs (
    id            INTEGER PRIMARY KEY,
    video_id      INTEGER REFERENCES videos(id) ON DELETE SET NULL,
    task_id       INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
    level         TEXT NOT NULL,
    stage         TEXT,
    message       TEXT NOT NULL,
    duration_ms   INTEGER,
    cost_estimate REAL,
    created_at    TEXT NOT NULL
);
CREATE INDEX idx_logs_video ON logs(video_id);
CREATE INDEX idx_logs_created ON logs(created_at);

CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO settings (key, value, updated_at) VALUES
    ('paused',              '0',            datetime('now')),
    ('daily_publish_limit', '1',            datetime('now')),
    ('publish_hour',        '12',           datetime('now')),
    ('timezone',            'Asia/Almaty',  datetime('now')),
    ('retry_limit',         '3',            datetime('now')),
    ('last_daily_run',      '',             datetime('now'));
