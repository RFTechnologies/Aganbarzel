import { 
    Card,
    BlockStack,
    Layout,
    Page,
    Button,
    Modal,
    TextField,
    TextContainer,
    Toast,
    Frame,
    IndexTable,
    Text,
    FormLayout,
    useIndexResourceState
  } from "@shopify/polaris";
  import React,{useState,useCallback,useEffect} from "react";
  
  import { fetch } from "../app";
  
  
  export function Rals(){
      const  [items,setItems] = useState([]);
      const [active, setActive] = useState(false);
      const [hasResults, setHasResults] = useState(false);
      const [toastContent, setToastContent] = useState('');
      const [isLoading, setIsLoading] = useState(true);
      const [name,setName] = useState('');
      const [code, setCode] = useState('');
      const [price, setPrice] = useState('');
      const [time, setTime] = useState('');
      const [isRequired, setIsRequired] = useState(false);
     
      const toggleModal = useCallback(() => setActive((active) => !active), []);
  
      const deleteRals = async function(){
        const formData = new FormData();
        formData.append('rals',selectedResources);
        const data = await fetch('/rals/delete',{
            method: 'post',
            body: formData
        }).then(res=>res.json());
        if(data.status == 'success'){
          setItems(data.items);
          setToastContent('Ral(s) deleted successfully!');
          setTimeout(()=>{
            setHasResults(true);
          },200);
        }else{
          setToastContent('Failed to delete!');
          setTimeout(()=>{
            setHasResults(true);
          },200);
        }
      }

      const addRal = async function(){
        if(name == '' || code == '' || price == ''){
            setIsRequired(true);
            return;
        }
        const formData = new FormData();
        formData.append('name',name);
        formData.append('price',price);
        formData.append('code',code);
        formData.append('time',time);
        const data = await fetch('/rals/add',{
            method: 'post',
            body: formData
        }).then((res)=>{
            setHasResults(true);
            return res.json();
        });
        if(data.status == 'success'){
            setItems(data.items);
            toggleModal();
            setName('');
            setCode('');
            setPrice('');
            setTime('');
            setToastContent('Ral Added!');
            setTimeout(()=>{
                setHasResults(true);
            },200);
        }else{
            toggleModal();
            setName('');
            setCode('');
            setPrice('');
            setTime('');
            setToastContent(data.message);
            setTimeout(()=>{
              setHasResults(true);
            },200);
        }
      }
  
      useEffect(()=>{
        async function fetchData(){
          try {
            const data = await fetch('/rals').then((res)=>res.json());
            setItems(data.items);
            if(data){
              setIsLoading(false);
            }
          } catch (error) {
            console.log("Error",error)
          }

        }
        fetchData();
      },[]);
  
      const toastMarkup = hasResults && (
        <div style={{'height':'150px'}}>
        <Frame>
          <Toast
            content= {toastContent}
            duration={3000}
            onDismiss={() => setHasResults(false)}
          />
        </Frame>
        </div>
      );

      const resourceName = {
        singular: "RAL",
        plural: "RALs",
      };
    
      const { selectedResources, allResourcesSelected, handleSelectionChange } =
        useIndexResourceState(items);
    
      const bulkActions = [
        {
          content: "Remove RALs",
          onAction: () => deleteRals(),
        },
      ];
    
      const rowMarkup = items.map(
        ({ id, name, code, price, time }, index) => (
          <IndexTable.Row
            id={id}
            key={id}
            selected={selectedResources.includes(id)}
            position={index}
          >
            <IndexTable.Cell>
              <Text as="span" fontWeight="bold" >{name}</Text>
            </IndexTable.Cell>
            <IndexTable.Cell>{code}</IndexTable.Cell>
            <IndexTable.Cell>{price} ₪</IndexTable.Cell>
            <IndexTable.Cell>{time}</IndexTable.Cell>
          </IndexTable.Row>
        )
      );
  
      return(
        <>
          <Page fullWidth>
          <Layout>
            <Layout.Section secondary>
            <Card>
                <div style={{ padding: "8px", display: "flex",justifyContent: "end" }}>
                    <Button onClick={toggleModal}>Add RAL</Button>
                </div>
                <IndexTable
                        resourceName={resourceName}
                        itemCount={items.length}
                        loading={isLoading}
                        selectedItemsCount={
                        allResourcesSelected ? "All" : selectedResources.length
                        }
                        onSelectionChange={handleSelectionChange}
                        bulkActions={bulkActions}
                        headings={[
                        { title: "Name" },
                        { title: "Code" },
                        { title: "Price" },
                        { title: "Time" },
                        ]}
                    >
                        {rowMarkup}
                </IndexTable>
            </Card>
          </Layout.Section>
          </Layout>
          <Modal
            open={active}
            onClose={toggleModal}
            title="Add a Tag"
            primaryAction={{
              content: 'Close',
              onAction: toggleModal,
            }}
            secondaryActions={{
                content: 'Add Ral',
                onAction: addRal,
            }}
          >
            <Modal.Section>
              <BlockStack>
                { isRequired &&
                <div>
                  <TextContainer>
                  <p style={{color: "red"}}>
                    Please fill in all required fields first.
                  </p>
                  </TextContainer>
                </div>
                }
                <div fill>
                <FormLayout>
                    <FormLayout.Group>
                        <TextField
                            type="text"
                            label="Name"
                            value={name}
                            requiredIndicator
                            placeholder="RAL 6000"
                            onChange={(value) => {setName(value)}}
                        />
                        <TextField
                            type="text"
                            value={code}
                            label="Code"
                            requiredIndicator
                            placeholder="#ffffff"
                            onChange={(value) => {setCode(value)}}
                        />
                    </FormLayout.Group>
                    <FormLayout.Group>
                        <TextField
                            type="number"
                            label="Price"
                            value={price}
                            placeholder="400"
                            requiredIndicator
                            onChange={(value) => {setPrice(value)}}
                        />
                        <TextField
                            type="text"
                            label="Time"
                            value={time}
                            placeholder="2 days"
                            onChange={(value) => {setTime(value)}}
                        />
                    </FormLayout.Group>
                </FormLayout>
                </div>
              </BlockStack>
            </Modal.Section>
          </Modal>
          {toastMarkup}
          </Page>
        </>
      );
  }