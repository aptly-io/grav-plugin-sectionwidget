/* Copyright 2015 Francis Meyvis */

/** Part of the Grav sectionwidget plugin*/

var sectionwidgetPREV_IDX_ID = -10;
var sectionwidgetNEXT_IDX_ID = -20;
var sectionwidgetALL_IDX_ID  = -30;

// global with the current active section
var sectionwidgetId = 0;
// global with the section information
var sectionwidgetInfo;


function sectionwidgetHandleSelection(sectionId)
{
    switch(sectionId) {
    case sectionwidgetPREV_IDX_ID:
        sectionId = sectionwidgetId - 1; break;
    case sectionwidgetNEXT_IDX_ID:
        if (sectionwidgetId < sectionwidgetInfo.length - 2) {
            sectionId = sectionwidgetId + 1;
        } else {
            return false; // Avoid 'next' into full page
        }
        break;
    case sectionwidgetALL_IDX_ID:
        sectionId = sectionwidgetInfo.length - 1; break;
    }

    if (sectionwidgetId != sectionId) {

        if (sectionId == sectionwidgetInfo.length - 1) {
            // show all
            var sectionDivs = document.getElementsByClassName("sw_hideable");
            for (var i = 0; i < sectionDivs.length; i++) {
                $(sectionDivs[i]).fadeIn("slow");
            }

            updateGoogleMaps();

        } else if (0 <= sectionId && sectionId < sectionwidgetInfo.length) {
            // show specific section
            var sectionDivs = document.getElementsByClassName("sw_hideable");
            for (var i = 0; i < sectionDivs.length; i++) {
                $(sectionDivs[i]).hide();
            }

            var id = "sw_section" + sectionId;
            var sectionDiv = document.getElementById(id);
            $(sectionDiv).fadeIn("slow");

            // update prev/next titles
            if (0 < sectionId) {
                var c = $('.sw_prev_control').children(":first-child");
                $(c).prop('title', sectionwidgetInfo[sectionId - 1].title);
            }
            if (sectionId < sectionwidgetInfo.length - 1) {
                var c = $('.sw_next_control').children(":first-child");
                $(c).prop('title', sectionwidgetInfo[sectionId + 1].title);
            }

            updateGoogleMaps();

        } else {
            return false;
        }

        // update active item
        $('.sw_menu_control').children(":first-child").text(sectionwidgetInfo[sectionId].title);
        var items = document.getElementsByClassName("dropdown-menu-item");
        for (var i = 0; i < items.length; i++) {
            $(items[i]).children(":first-child").removeClass("active");
        }
        $(items[sectionId]).children(":first-child").addClass("active");

        sectionwidgetId = sectionId;
    }
    return false; // prevents the browser to follow href attribute
}


function sectionwidgetInit(sectionInfo, initialSectionIdx)
{
    if (0 < sectionInfo.length) {

        // Make sure all sections are hidden fast
        var sectionDivs = document.getElementsByClassName("sw_hideable");
        for (var i = 0; i < sectionDivs.length; i = i + 1) {
            $(sectionDivs[i]).hide();
        }

        sectionwidgetInfo = sectionInfo;
        sectionwidgetId = -1; // make sure updates happen
        sectionwidgetHandleSelection(initialSectionIdx);
    }
}


/** Let GoogleMaps plugin update its google map*/
function updateGoogleMaps()
{
    if (typeof gm_updateMaps == 'function') {
        gm_updateMaps(); // Hack for the GoogleMap plug-in: make sure maps are refreshed
    }
}