/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

/**
 * The "Type" select of one Product Attachments row, showing the fields that
 * type actually uses and hiding the rest.
 *
 * A subclass rather than `switcherConfig` because `Magento_Ui/js/form/switcher`
 * is a bare uiClass with no tie to the row that owns it, and resolves its
 * targets through the silent callback form of `registry.get` — inside
 * `dynamicRows`, where records are destroyed and rebuilt, it fails with no
 * error at all. This is the shape core itself uses for the same job; see
 * `Magento_Catalog/js/custom-options-type`.
 */
define([
    'underscore',
    'uiRegistry',
    'Magento_Ui/js/form/element/select'
], function (_, registry, Select) {
    'use strict';

    return Select.extend({
        defaults: {
            /**
             * {<option value>: {show: [index, ...], hide: [index, ...]}},
             * supplied by the Ui modifier.
             */
            typeMap: {},
            previousValue: null,
            listens: {
                value: 'toggleSiblings'
            }
        },

        /**
         * @inheritdoc
         */
        initialize: function () {
            this._super();
            // `listens` only fires on a change, so the row's initial state has
            // to be seeded by hand.
            this.toggleSiblings(this.value(), true);

            return this;
        },

        /**
         * @param {String} value
         * @param {Boolean} [isInit]
         */
        toggleSiblings: function (value, isInit) {
            // An unrecognised type (a hand-edited row, a type dropped from a
            // later version) falls back to the first mapping rather than
            // leaving the row with no editor at all — both are hidden until
            // something reveals one.
            var map = this.typeMap[value] || _.values(this.typeMap)[0];

            if (!map || value === this.previousValue && !isInit) {
                return;
            }

            this.previousValue = value;

            _.each({show: true, hide: false}, function (visible, key) {
                _.each(map[key] || [], function (index) {
                    // `parentName` is this row's record, so siblings are
                    // addressed row-locally. Async because on the first render
                    // they may not be registered yet; later toggles resolve
                    // straight away.
                    registry.async(this.parentName + '.' + index)(function (field) {
                        field.visible(visible);

                        // Not on the initial seed: that would wipe the value
                        // the row was just loaded with.
                        if (!visible && !isInit && _.isFunction(field.clear)) {
                            field.clear();
                        }
                    });
                }, this);
            }, this);
        }
    });
});
