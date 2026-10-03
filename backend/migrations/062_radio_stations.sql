-- Radio stations: playlists on the one radio number, picked from a menu —
-- "Press 1 for party songs" — or straight away on a phone that has one as
-- its favourite (devices.radio_station_id). A song can be on several. Each
-- station plays shuffled or in the songs' order. name_audio is the household
-- saying its name, for the menu. See Radio.
CREATE TABLE IF NOT EXISTS radio_stations (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(60)  NOT NULL DEFAULT '',
    position   INT UNSIGNED NOT NULL DEFAULT 0,
    shuffle    TINYINT(1)   NOT NULL DEFAULT 1,
    name_audio VARCHAR(80)  NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radio_station_songs (
    station_id INT UNSIGNED NOT NULL,
    song_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (station_id, song_id),
    CONSTRAINT fk_station_songs_station FOREIGN KEY (station_id) REFERENCES radio_stations (id) ON DELETE CASCADE,
    CONSTRAINT fk_station_songs_song FOREIGN KEY (song_id) REFERENCES radio_songs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A calm song: the ones the radio plays at bedtime, if it's set to.
ALTER TABLE radio_songs
  ADD COLUMN IF NOT EXISTS calm TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled;

-- A phone's favourite station: dialling the radio plays it straight away.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS radio_station_id INT UNSIGNED NULL;
