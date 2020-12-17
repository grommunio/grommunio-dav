<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 - 2018 Kopano b.v.
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Version file for GrammmDAV.
 */

if (!defined("GDAV_VERSION")) {
    $path = escapeshellarg(dirname(realpath($_SERVER['SCRIPT_FILENAME'])));
    $branch = trim(exec("hash git 2>/dev/null && cd $path >/dev/null 2>&1 && git branch --no-color 2>/dev/null | sed -e '/^[^*]/d' -e \"s/* \(.*\)/\\1/\""));
    $version = exec("hash git 2>/dev/null && cd $path >/dev/null 2>&1 && git describe  --always 2>/dev/null");
    if ($branch && $version) {
        define("GDAV_VERSION", $branch .'-'. $version);
    }
    else {
        define("GDAV_VERSION", "GIT");
    }
}
