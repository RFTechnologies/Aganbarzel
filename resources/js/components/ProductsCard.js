import React,{ useEffect, useState, useCallback } from "react";
import moment from 'moment';
import {
  Card,
  Text,
  ResourceList,
  ResourceItem,
  Modal,
  TextContainer,
  DatePicker,
  BlockStack,
  Toast,
  Frame,
  EmptyState
} from "@shopify/polaris";
import { fetch } from "../app";

export function ProductsCard() {
  const [items, setItems] = useState([]);
  const [selectedItem, setSelectedItem] = useState(null);
  const [isEmpty, setIsEmpty] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [hasResults, setHasResults] = useState(false);
  const [active, setActive] = useState(false);
  const [toastContent,setToastContent] = useState('');
  const tomorrow = function(d){ d.setDate(d.getDate()+1); return d}(new Date);

  const [{month, year}, setDate] = useState({month: (new Date().getMonth()), year: (new Date().getFullYear())});
  const [selectedDates, setSelectedDates] = useState({
    start: tomorrow,
    end: tomorrow,
  });

  const handleMonthChange = useCallback(
    (month, year) => setDate({month, year}),
    [],
  );
 

  const toggleModal = useCallback(() => setActive((active) => !active), []);

  async function getItems(){
    const data = await fetch('/products/getProducts').then((res)=>res.json());
    setItems(data.items);
    setIsLoading(false);
    if(data.items.length == 0){
      setIsEmpty(true);
    }
  }

  function openPopup(value){
    setActive(true);
    setSelectedItem(value);
  }

  async function deleteItem(){
    const data = await fetch('/tags/deleteProducts/'+selectedItem).then(res=>res.json());
    if(data.status == 'success'){
      setActive(false);
      setToastContent(data.message);
      await getItems();
      setTimeout(function(){
        setHasResults(true);
      },200);
    }
  }

  async function updateDate(){
    const formData = new FormData();
    formData.append('id', selectedItem);
    formData.append('date', moment(selectedDates.start).format('lll'));
    const data = await fetch('/tags/updateDate',{
      method: 'POST',
      body: formData
    }).then(res=>res.json());
    if(data.status == 'success'){
      setActive(false);
      setToastContent(data.message);
      await getItems();
      setTimeout(function(){
        setHasResults(true);
      },200);
    }
  }

  useEffect(() => {
    getItems();
  }, []);

  const toastMarkup = hasResults && (
    <Frame>
    <Toast
      content={toastContent}
      onDismiss={() => setHasResults(false)}
    />
    </Frame>
  );

  return (
    <>
      <Card title="All Products" sectioned>
        {
          isEmpty && (<EmptyState
          heading="Oops, it's look like your tag list is empy. "
        >
          <p>
            Either your tag list is empty or there is no product with these tags.
          </p>
        </EmptyState>)
        }
      { !isEmpty && 
      (<ResourceList
        loading={isLoading}
        resourceName={{singular: 'product', plural: 'products'}}
        items={items}
        renderItem={(item) => {
        const {id,tag, delete_date,products_size,variants_size} = item;

      return (
        <ResourceItem
              onClick={openPopup}
                id={id}
                accessibilityLabel={`View details for ${tag}`}
              >
                <BlockStack gap={'025'}>
                  <BlockStack gap={'025'}>
                    <h3>
                      <Text as="span" fontWeight="bold" variation="strong">{tag}</Text>
                    </h3>
                    <div>{products_size} products, {variants_size} variants.</div>
                    { delete_date && (<div>
                      <Text as="span" tone="caution">Product will be deleted at {delete_date}</Text>
                      </div>)}
                  </BlockStack>
                </BlockStack>
              </ResourceItem>
            );
          }}
        />)
      }
      </Card>
      {toastMarkup}
      <Modal
          open={active}
          onClose={toggleModal}
          title="Delete Products"
          primaryAction={{
            content: 'Close',
            onAction: toggleModal
          }}
          secondaryActions={[
            {
              content: 'Delete Immediately',
              onAction: deleteItem,
            },
            {
              content: 'Set Date',
              onAction: updateDate
            }
          ]}
        >
        <Modal.Section>
            <BlockStack>
              <div>
                <TextContainer>
                <p>
                  Pick date from date-picker or delete products and their related variants with this tag.
                </p>
                <DatePicker
                  month={month}
                  year={year}
                  onChange={setSelectedDates}
                  onMonthChange={handleMonthChange}
                  
                  selected={selectedDates}
                />
                </TextContainer>
              </div>
            </BlockStack>
          </Modal.Section>
        </Modal>
    </>
  );
}
