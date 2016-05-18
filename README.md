# xedi

## Introduction

Tool to consume and convert LSP's Billing Report to Non-standard EDI.

- always testing 1
- A model driven tool to read provided model per specific file pattern.
- Scalable since its config base.
- Cant build men read UTF-8 data.
- With configurable messaging base on $LANG provided in specific model.

# usage

## Defining Model
[1] clone repo
````bash
git clone https://github.com/TraxTechnologies/xedi.git
````
[2] copy any existing {HENLEXPORT.ini} file in reference model folder.

- reference: https://github.com/TraxTechnologies/xedi/blob/xedi-dev/model/HENLEXPORT.ini

[3] create your own mapping base on LSP's file structure.

````php
[Base] # base should be present when creating model reading data per row col
Lang=CHN
Customer=STRYKER #required
SCAC=HENL #required
rowstart=9 #required
columrange=0:21 #required
delimiter=* #required

[CustomBase] # reading file per horizontal arrange vertically
rowstart=2
rowend=5
columrange=0:21

[CustomBaseColumns] # Column for Custom
value[ ]=CompanyName
value[ ]=ClientContactPerson
value[ ]=Contact1
value[ ]=StatementNo
value[ ]=DateOfStatement

[Columns] # column mapping for Base
value[ ]=HAWB
value[ ]=Pics
value[ ]=DeclarationNo
value[ ]=ExportPorts
value[ ]=LineInfo*Freight # LineInfo added thru config as parent segment equivalent to L1 for standard use in EDI mapping
value[ ]=LineInfo*CustomsFee
value[ ]=LineInfo*SheetPatchUp
value[ ]=Total
````

[4] during mapping of fields ensure provided columnrange count should be equivalent to the number of elements declared under [Columns].

[5] after model is created, add config to model.ini file.

## File pattern config
[0] ensure file pattern and model added in config.
- example:
```json
YGX_STRYKER_6167.xlsx
```
- reference: https://github.com/TraxTechnologies/xedi/blob/xedi-dev/models.ini

````php
[Stryker]
config['*YGX*'] = "YGX" # ensuring 
config['*KWE*'] = "KWE"
config['*EXP*'] = "EXPC"
config['*henglong_importarea*'] = "HENLIMPORTAREA"
config['*henglong_import*'] = "HENLIMPORT"
config['*henglong_export*'] = "HENLEXPORT"
config['*henglong_recordagency*'] = "HENLANGENCY"
config['*henglong_outward*'] = "HENLOUTWARD"
````

[6] push your changes to repo:
````bash
git checkout -b <branchname>
git status #to check whether your changes still not added on that branch (red)
git add . #to add your changes on the branch created.
git status #to verify if added (green)
git commit -am '<brief description of the changes>'
git push origin <branchname> #to push changes to repo
````

[7] next is go to repo and create a merge pull request and create ccb to merge your branch to master.
