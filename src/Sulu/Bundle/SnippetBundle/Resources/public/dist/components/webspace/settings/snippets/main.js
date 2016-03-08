define(["app-config"],function(a){"use strict";var b={options:{snippetTypesUrl:"/admin/api/snippet-types?defaults=true&webspace=<%= webspace %>",snippetTypeDefaultUrl:"/admin/api/snippet-types/<%= type %>/default?webspace=<%= webspace %>",snippetsUrl:"/admin/api/snippets?type=<%= type %>&language=<%= locale %>"},templates:{datagrid:'<div id="<%= ids.datagrid %>"></div><div id="<%= ids.overlayContainer %>"></div>',overlay:['<div class="grid">','   <div class="grid-row search-row">','       <div class="grid-col-8"/>','       <div class="grid-col-4" id="<%= ids.overlayDatagridSearch %>"/>',"   </div>",'   <div class="grid-row">','       <div class="grid-col-12" id="<%= ids.overlayDatagrid %>"/>',"   </div>","</div>"].join("")},translations:{snippetType:"snippets.defaults.type",defaultSnippet:"snippets.defaults.default",overlayTitle:"snippets.defaults.default"}};return{defaults:b,ids:{datagrid:"snippet-types",overlayContainer:"overlay",overlayDatagrid:"snippets",overlayDatagridSearch:"snippets-search"},tabOptions:function(){return{title:this.data.webspace.title}},layout:{content:{leftSpace:!0,rightSpace:!0}},initialize:function(){this.render()},render:function(){this.html(this.templates.datagrid({ids:this.ids})),this.startDatagrid()},startDatagrid:function(){this.sandbox.start([{name:"datagrid@husky",options:{el:this.$find("#"+this.ids.datagrid),instanceName:"snippets",idKey:"template",viewOptions:{table:{selectItem:!1,icons:[{icon:"plus-circle",column:"defaultTitle",align:"right",cssClass:"no-hover",disableCallback:function(a){return!a.defaultUuid},callback:this.openOverlay.bind(this)},{icon:"times",column:"defaultTitle",align:"right",cssClass:"no-hover simple",disableCallback:function(a){return!!a.defaultUuid},callback:this.removeDefault.bind(this)}]}},matchings:[{attribute:"title",content:this.translations.snippetType},{attribute:"defaultTitle",content:this.translations.defaultSnippet}],data:this.data.types}}])},openOverlay:function(a){var b=$("<div/>");this.$find("#"+this.ids.overlayContainer).append(b),this.sandbox.start([{name:"overlay@husky",options:{el:b,instanceName:"snippets",openOnStart:!0,slides:[{title:this.translations.overlayTitle,data:this.templates.overlay({ids:this.ids}),buttons:[{type:"cancel",align:"center"}]}]}}]).then(function(){this.startSnippetDatagrid(a)}.bind(this))},startSnippetDatagrid:function(b){this.sandbox.start([{name:"search@husky",options:{el:this.$find("#"+this.ids.overlayDatagridSearch),appearance:"white small",instanceName:this.ids.overlayDatagridSearch}},{name:"datagrid@husky",options:{el:this.$find("#"+this.ids.overlayDatagrid),url:_.template(this.options.snippetsUrl,{type:b,locale:a.getUser().locale}),resultKey:"snippets",sortable:!1,searchInstanceName:this.ids.overlayDatagridSearch,viewOptions:{table:{selectItem:!1,icons:[{icon:"check-circle",column:"title",callback:function(a){this.saveDefault(b,a)}.bind(this)}]}},matchings:[{content:"Title",type:"title",width:"100%",name:"title",editable:!0,sortable:!0}]}}])},saveDefault:function(a,b){var c=_.template(this.options.snippetTypeDefaultUrl,{type:a,webspace:this.options.webspace});this.sandbox.util.save(c,"PUT",{"default":b}).then(function(a){this.sandbox.emit("husky.overlay.snippets.close"),this.sandbox.emit("husky.datagrid.snippets.records.change",a),this.sandbox.emit("sulu.labels.success.show","labels.success.content-save-desc","labels.success")}.bind(this))},removeDefault:function(a,b){var c=_.template(this.options.snippetTypeDefaultUrl,{type:a,webspace:this.options.webspace});this.sandbox.util.save(c,"DELETE",{"default":b}).then(function(a){this.sandbox.emit("husky.datagrid.snippets.records.change",a),this.sandbox.emit("sulu.labels.success.show","labels.success.content-save-desc","labels.success")}.bind(this))},loadComponentData:function(){var a=this.sandbox.data.deferred();return this.sandbox.util.load(_.template(this.options.snippetTypesUrl,{webspace:this.options.webspace})).then(function(b){a.resolve({webspace:this.options.data(),types:b._embedded})}.bind(this)),a.promise()}}});