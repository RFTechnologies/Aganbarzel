import React from "react";
import { Card } from "@shopify/polaris";
import {NavLink} from 'react-router-dom';

export function Navigation(){
    return(
      <Card subdued>
        <div className="Polaris-Tabs__Wrapper">
          <ul role="tablist" className="Polaris-Tabs">
            <li className="Polaris-Tabs__TabContainer" role="presentation">
              <NavLink to="/" className={({isActive})=>isActive? 'Polaris-Tabs__Tab Polaris-Tabs__Tab--selected':'Polaris-Tabs__Tab'}>
                <span className="Polaris-Tabs__Title">Home</span>
              </NavLink>
            </li>
            <li className="Polaris-Tabs__TabContainer"  role="presentation">
                <NavLink to="/tags" className={({isActive})=>isActive? 'Polaris-Tabs__Tab Polaris-Tabs__Tab--selected':'Polaris-Tabs__Tab'}>
                    <span className="Polaris-Tabs__Title">Tags</span>
                </NavLink>
            </li>
            <li className="Polaris-Tabs__TabContainer"  role="presentation">
                <NavLink to="/rals" className={({isActive})=>isActive? 'Polaris-Tabs__Tab Polaris-Tabs__Tab--selected':'Polaris-Tabs__Tab'}>
                    <span className="Polaris-Tabs__Title">RAL Colors</span>
                </NavLink>
            </li>
          </ul>
        </div>
      </Card>
    );
}