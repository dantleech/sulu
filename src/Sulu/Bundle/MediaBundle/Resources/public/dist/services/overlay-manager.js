define(function(){"use strict";function a(){}var b=null,c=function(a){var b=$('<div id="'+a+'"/>');return $("body").append(b),b};return a.prototype={startCreateCollectionOverlay:function(a){var b=a&&a.id?a.id:null,d=c("create-collection-overlay");this.sandbox.start([{name:"collections/collection-create-overlay@sulumedia",options:{el:d,parent:b}}])},startMoveMediaOverlay:function(a,b){$.isArray(a)||(a=[a]);var d=c("select-collection-overlay");this.sandbox.start([{name:"collections/collection-select-overlay@sulumedia",options:{el:d,instanceName:"move-media",title:this.sandbox.translate("sulu.media.move.overlay-title"),locale:b,disableIds:a}}])},startMoveCollectionOverlay:function(a,b){$.isArray(a)||(a=[a]);var d=c("select-collection-overlay");this.sandbox.start([{name:"collections/collection-select-overlay@sulumedia",options:{el:d,instanceName:"move-collection",title:this.sandbox.translate("sulu.collection.move.overlay-title"),rootCollection:!0,disableIds:a,disabledChildren:!0,locale:b}}])},startEditMediaOverlay:function(a,b){$.isArray(a)||(a=[a]);var d=c("edit-media-overlay");this.sandbox.start([{name:"collections/media-edit-overlay@sulumedia",options:{el:d,mediaIds:a,locale:b}}])},startEditCollectionOverlay:function(a,b){var d=c("edit-collection-overlay");this.sandbox.start([{name:"collections/collection-edit-overlay@sulumedia",options:{el:d,collectionId:a,locale:b}}])}},a.getInstance=function(){return null===b&&(b=new a),b},a.getInstance()});