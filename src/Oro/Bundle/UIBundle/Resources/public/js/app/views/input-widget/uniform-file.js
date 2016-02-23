define(function(require) {
    'use strict';

    var UniformFileInputWidget;
    var AbstractInputWidget = require('oroui/js/app/views/input-widget/abstract');
    var __ = require('orotranslation/js/translator');

    UniformFileInputWidget = AbstractInputWidget.extend({
        widgetFunctionName: 'uniform',

        initializeOptions: {
            fileDefaultHtml: __('Please select a file...'),
            fileButtonHtml: __('Choose File')
        },

        refreshOptions: 'update',

        initialize: function(options) {
            UniformFileInputWidget.__super__.initialize.apply(this, arguments);
            if (this.$el.is('.error')) {
                this.$el.removeClass('error').closest('.uploader').addClass('error');
            }
        },

        dispose: function() {
            if (this.disposed) {
                return;
            }
            this.$el.uniform.restore();
            UniformFileInputWidget.__super__.dispose.apply(this, arguments);
        },

        getContainer: function() {
            var $parent = this.$el.parent('.uploader');
            return $parent.length > 0 ? $parent : null;
        }
    });

    return UniformFileInputWidget;
});
