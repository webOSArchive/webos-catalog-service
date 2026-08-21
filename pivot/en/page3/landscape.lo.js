[
	{kind: "VFlexBox", className: "landscape", style: "background-image: url({$landscapeBgImage});width:1024px;height:768px;", components: [
        {kind: 'enyo.FindApps.Magazine.BindableLayout',
         templatePath: "{$landscapeHeaderTemplate}", bindingPath: "{$headerTemplateBindings}"},

        {content: "", className: "toc-feature hidden", target: "{$targetFeature1}", onclick: "goToTargetAction"},
        {content: "", className: "toc-feature1 hidden", target: "{$targetFeature1}", onclick: "goToTargetAction"},
        {content: "", className: "toc-shutter hidden", target: "{$targetShutter}", onclick: "goToTargetAction"},
        {content: "", className: "toc-shutter1 hidden", target: "{$targetShutter}", onclick: "goToTargetAction"},
        {content: "", className: "toc-index hidden", target: "{$targetIndex}", onclick: "goToTargetAction"},
        {content: "", className: "toc-index1 hidden", target: "{$targetIndex}", onclick: "goToTargetAction"},
        {content: "", className: "toc-teaser", target: "{$targetTeaser}", onclick: "goToTargetAction"},
        {content: "", className: "toc-teaser1", target: "{$targetTeaser}", onclick: "goToTargetAction"}
	]}
]
