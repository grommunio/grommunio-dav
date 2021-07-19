<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Class for handling sync state.
 */

namespace grommunio\DAV;

class GrommunioSyncState {
    private $db;

    /**
     * Constructor.
     *
     * @param GLogger $logger
     * @param string $dbstring
     */
    public function __construct($logger, $dbstring) {
        $this->logger = $logger;
        $this->logger->trace("Using db %s", $dbstring);
        $this->db = new \PDO($dbstring);

        $query = "CREATE TABLE IF NOT EXISTS gdav_sync_state (
            id VARCHAR(255), folderid VARCHAR(255), value TEXT,
            PRIMARY KEY (id, folderid));
            CREATE TABLE IF NOT EXISTS gdav_sync_appttsref (
            sourcekey VARCHAR(255), folderid VARCHAR(255), appttsref VARCHAR(255),
            PRIMARY KEY (sourcekey, folderid));";

        $this->db->exec($query);
    }

    /**
     * Fetch state information for a folderId (e.g. calenderId) and an id (uuid).
     *
     * @param string $folderid
     * @param string $id
     *
     * @access public
     * @return string
     */
    public function getState($folderid, $id) {
        $query = "SELECT value FROM gdav_sync_state WHERE folderid = :folderid AND id = :id";
        $statement = $this->db->prepare($query);
        $statement->bindParam(":folderid", $folderid);
        $statement->bindParam(":id", $id);
        $statement->execute();
        $result = $statement->fetch();
        if (!$result) {
            return null;
        }
        return $result['value'];
    }

    /**
     * Set state information for a folderId (e.g. calenderId) and an id (uuid).
     * The state information is the sync token for ICS.
     *
     * @param string $folderid
     * @param string $id
     * @param string $value
     *
     * @access public
     * @return void
     */
    public function setState($folderid, $id, $value) {
        $query = "REPLACE INTO gdav_sync_state (id, folderid, value) VALUES(:id, :folderid, :value)";
        $statement = $this->db->prepare($query);
        $statement->bindParam(":folderid", $folderid);
        $statement->bindParam(":id", $id);
        $statement->bindParam(":value", $value);
        $statement->execute();
    }

    /**
     * Set the APPTTSREF (custom URL) for a folderId and source key.
     * This is needed for detecting the URL of deleted items reported by ICS.
     *
     * @param string $folderid
     * @param string $sourcekey
     * @param string $appttsref
     *
     * @access public
     * @return void
     */
    public function rememberAppttsref($folderid, $sourcekey, $appttsref) {
        $query = "REPLACE INTO gdav_sync_appttsref (folderid, sourcekey, appttsref) VALUES(:folderid, :sourcekey, :appttsref)";
        $statement = $this->db->prepare($query);
        $statement->bindParam(":folderid", $folderid);
        $statement->bindParam(":sourcekey", $sourcekey);
        $statement->bindParam(":appttsref", $appttsref);
        $statement->execute();
    }

    /**
     * Get the APPTTSREF (custom URL) for a folderId and source key.
     * This is needed for detecting the URL of deleted items reported by ICS.
     *
     * @param string $folderid
     * @param string $sourcekey
     *
     * @access public
     * @return string
     */
    public function getAppttsref($folderid, $sourcekey) {
        $query = "SELECT appttsref FROM gdav_sync_appttsref WHERE folderid = :folderid AND sourcekey = :sourcekey";
        $statement = $this->db->prepare($query);
        $statement->bindParam(":folderid", $folderid);
        $statement->bindParam(":sourcekey", $sourcekey);
        $statement->execute();
        $result = $statement->fetch();
        if (!$result) {
            return null;
        }
        return $result['appttsref'];
    }
}
