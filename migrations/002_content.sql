-- Этапы 2-5: сценарии со сценами, история тем, проверка фактов, сборка, публикация.

ALTER TABLE videos ADD COLUMN topic_key TEXT;
ALTER TABLE videos ADD COLUMN category TEXT;
ALTER TABLE videos ADD COLUMN meta_json TEXT;          -- сцены, хук, хэштеги, утверждения, отчёт проверки
ALTER TABLE videos ADD COLUMN script_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE videos ADD COLUMN regen_hint TEXT;
ALTER TABLE videos ADD COLUMN audio_duration REAL;
ALTER TABLE videos ADD COLUMN video_duration REAL;
ALTER TABLE videos ADD COLUMN manifest_json TEXT;      -- задание для сборщика видео
ALTER TABLE videos ADD COLUMN render_claimed_at TEXT;
ALTER TABLE videos ADD COLUMN approval TEXT NOT NULL DEFAULT 'none';  -- none|pending|approved|rejected
ALTER TABLE videos ADD COLUMN upload_session TEXT;
ALTER TABLE videos ADD COLUMN upload_started_at TEXT;

ALTER TABLE assets ADD COLUMN scene_index INTEGER;
ALTER TABLE assets ADD COLUMN remote_url TEXT;
ALTER TABLE assets ADD COLUMN external_id TEXT;

CREATE TABLE topic_history (
    id         INTEGER PRIMARY KEY,
    video_id   INTEGER REFERENCES videos(id) ON DELETE SET NULL,
    topic_key  TEXT NOT NULL,
    title      TEXT NOT NULL,
    category   TEXT,
    outcome    TEXT NOT NULL DEFAULT 'generated',   -- generated|published|rejected
    created_at TEXT NOT NULL
);
CREATE INDEX idx_topic_history_created ON topic_history(created_at);
CREATE INDEX idx_assets_external ON assets(external_id);
