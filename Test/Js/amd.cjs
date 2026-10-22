/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

/**
 * Loads one of the module's AMD files in Node, with no dependency outside the module
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../../view/adminhtml/web/js');
const PREFIX = 'Hryvinskyi_McpServer/js/';

/**
 * @param {string} id e.g. "Hryvinskyi_McpServer/js/connection-card"
 * @returns {*} What the module's factory returns
 */
function load(id) {
    const file = path.join(ROOT, (id.startsWith(PREFIX) ? id.slice(PREFIX.length) : id) + '.js');
    let exported;

    vm.runInNewContext(fs.readFileSync(file, 'utf8'), {
        define: (deps, factory) => {
            exported = factory(...deps.map(load));
        },
        AbortController,
        WeakSet,
        Map,
        Error
    }, {filename: file});

    return exported;
}

module.exports = {load};
