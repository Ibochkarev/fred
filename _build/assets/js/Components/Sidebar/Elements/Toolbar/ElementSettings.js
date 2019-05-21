import ToolbarPlugin from "./ToolbarPlugin";
import elementSettings from '../ElementSettings';
import { a } from "../../../../UI/Elements";
import fredConfig from "../../../../Config";

export default class ElementSettings extends ToolbarPlugin {
    static checkInvalidTheme = false;
    
    render() {
        if (this.el.options.settings && (this.el.options.settings.length > 0)) {
            if ((this.el.options.remote === true) && (this.el.options.cacheOutput === true) && !fredConfig.permission.fred_element_cache_refresh) {
                return null;
            }
            
            return a('fred.fe.content.settings', 'fred.fe.content.settings', '', [], () => {elementSettings.open(this.el)});
        }
        
        return null;
    }
}