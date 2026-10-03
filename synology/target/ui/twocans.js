// twocans' window in DSM. DSM loads this from ui/config when twocans is
// opened from its main menu; the window shows panel.html, beside this,
// which DSM serves — so it's on DSM's own address, with its sign-in.
Ext.ns("TwoCans");

Ext.define("TwoCans.AppInstance", {
    extend: "SYNO.SDS.AppInstance",
    appWindowName: "TwoCans.AppWindow"
});

Ext.define("TwoCans.AppWindow", {
    extend: "SYNO.SDS.AppWindow",
    constructor: function (config) {
        this.callParent([Ext.apply({
            title: "twocans",
            width: 940,
            height: 660,
            minWidth: 480,
            minHeight: 360,
            resizable: true,
            maximizable: true,
            minimizable: true,
            layout: "fit",
            items: [{
                xtype: "box",
                autoEl: {
                    tag: "iframe",
                    src: "/webman/3rdparty/twocans/panel.html",
                    frameborder: 0,
                    style: "width:100%;height:100%;border:0;display:block"
                }
            }]
        }, config)]);
    }
});
