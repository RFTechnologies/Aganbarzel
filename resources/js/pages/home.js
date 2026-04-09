import React from "react";
import {Page,Layout,} from "@shopify/polaris";
import {ProductsCard} from '../components/ProductsCard';
  
export function Home() {
    return (
        <Page fullWidth>
          <Layout>
            <Layout.Section secondary>
                <ProductsCard />
            </Layout.Section>
          </Layout>
        </Page>
    );
}